<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Handler\PayloadHandler;

use Semitexa\Cms\Application\Payload\Request\ContentEditorPayload;
use Semitexa\Cms\Application\Service\ContentEditorPage;
use Semitexa\Cms\Application\Service\ContentSurfaceRegistry;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Cms\Domain\Model\ContentRow;
use Semitexa\Cms\Domain\Model\ContentRows;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Node;

/**
 * Opens a place on the map: a page as its editor, a collection as its list.
 *
 * The ref is resolved through the graph rather than trusted: only a record the
 * map actually carries can be opened, and the node is also what says WHICH
 * editor serves it. A ref typed into the URL that no place claims opens
 * nothing — which is the same rule that keeps one site's console away from
 * another's records.
 */
#[AsPayloadHandler(payload: ContentEditorPayload::class, resource: ResourceResponse::class)]
final class ContentEditorHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    #[InjectAsReadonly]
    protected ContentSurfaceRegistry $surfaces;

    #[InjectAsReadonly]
    protected ContentEditorPage $page;

    private const PER_PAGE = 25;

    /**
     * How far a name is looked for. The map is a dozen places per site, not a
     * table dump, so this is a ceiling against a runaway projection rather than
     * a paging limit anyone should hit.
     */
    private const NAME_SCAN_LIMIT = 500;

    public function handle(ContentEditorPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $ref = trim($payload->getRef());

        // A name only ever stands in for a missing ref. A ref is exact; a name
        // is a guess, and the guess must never override the certainty.
        $name = trim($payload->getName());
        if ($ref === '' && $name !== '') {
            $named = $this->resolveName($name);
            if (is_string($named)) {
                $ref = $named;
            } elseif ($named instanceof ContentRows) {
                return $this->html($resource, $this->page->renderRows($named, ''));
            } else {
                // Names the thing that was looked for, and does NOT suggest it
                // was deleted — a name this site never used is the ordinary
                // case, and a deletion hint here reads as an accusation.
                return $this->html($resource, $this->page->renderNameNotFound($name));
            }
        }

        $node = $ref === '' ? null : $this->graph->nodeByRef($ref);
        $properties = $node?->getProperties() ?? [];

        $html = match ($properties['opens'] ?? null) {
            'grid' => $this->grid($ref, (string) ($properties['source'] ?? ''), $payload->getPage()),
            'editor' => $this->editor($ref, (string) ($properties['editor'] ?? '')),
            // A row of a collection is not a place on the map — 85 events are
            // exactly what the map keeps OUT — so a ref with no node still has
            // to open. The editors decide: each is scoped to this tenant and
            // only loads refs it recognises, so nothing outside the site can be
            // reached by typing one.
            default => $this->editorForRef($ref),
        };

        return $this->html($resource, $html);
    }

    private function html(ResourceResponse $resource, string $html): ResourceResponse
    {
        return $resource
            ->setContent($html)
            ->setHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Turn a name a person said into the place they meant.
     *
     * Three answers, because "the page called Contacts" has three honest
     * outcomes and collapsing them loses the one that matters:
     *
     *  - a ref (string) when exactly one place is meant, and the caller carries
     *    on as though a ref had been asked for;
     *  - {@see ContentRows} when several places could be meant — rendered as the
     *    same list a collection opens, so every candidate is one click away
     *    rather than the person having to guess again in different words;
     *  - null when the name resolves to nothing, leaving the empty state to say
     *    so.
     *
     * An exact title wins outright: a site with 'Contacts' and 'Contacts (old)'
     * must not force a choice on someone who named one of them precisely.
     *
     * @return string|ContentRows|null
     */
    private function resolveName(string $name): string|ContentRows|null
    {
        if ($name === '') {
            return null;
        }

        $exact = [];
        $partial = [];

        foreach ($this->graph->graph(self::NAME_SCAN_LIMIT, [NodeKind::Page, NodeKind::Collection])['nodes'] as $node) {
            if (!$node instanceof Node || ($node->getRef() ?? '') === '') {
                continue;
            }
            $title = $node->getTitle();
            if (mb_strtolower($title) === mb_strtolower($name)) {
                $exact[] = $node;
            } elseif (mb_stripos($title, $name) !== false) {
                $partial[] = $node;
            }
        }

        $matches = $exact !== [] ? $exact : $partial;

        if ($matches === []) {
            return null;
        }
        if (count($matches) === 1) {
            return (string) $matches[0]->getRef();
        }

        return new ContentRows(
            title: 'Що з цього відкрити?',
            rows: array_map(
                static fn(Node $node): ContentRow => new ContentRow(
                    ref: (string) $node->getRef(),
                    title: $node->getTitle(),
                    meta: [$node->getKind() === NodeKind::Collection ? 'список' : 'сторінка'],
                ),
                $matches,
            ),
            total: count($matches),
            perPage: count($matches),
        );
    }

    private function editor(string $ref, string $editorId): string
    {
        $draft = $editorId === '' ? null : $this->surfaces->editor($editorId)?->load($ref);

        return $draft === null
            ? $this->page->renderMissing($ref)
            : $this->page->render($draft, $this->csrfToken());
    }

    private function editorForRef(string $ref): string
    {
        // Nothing was asked for. Offer the map instead of an apology: this is
        // what a dialog raised before anything is chosen looks like, and the
        // author is one click from where they were going.
        if ($ref === '') {
            return $this->chooseAPlace();
        }

        foreach ($this->surfaces->editors() as $editor) {
            $draft = $editor->load($ref);
            if ($draft !== null) {
                return $this->page->render($draft, $this->csrfToken());
            }
        }

        return $this->page->renderMissing($ref);
    }

    /**
     * Every place on the map, as the same list a collection opens.
     *
     * The empty state used to say "можливо, запис видалено" here, which accused
     * the author of removing a record they had never named — the editor had
     * simply been opened with nothing to open.
     */
    private function chooseAPlace(): string
    {
        $rows = [];
        foreach ($this->graph->graph(self::NAME_SCAN_LIMIT, [NodeKind::Page, NodeKind::Collection])['nodes'] as $node) {
            if (!$node instanceof Node || ($node->getRef() ?? '') === '') {
                continue;
            }
            $rows[] = new ContentRow(
                ref: (string) $node->getRef(),
                title: $node->getTitle(),
                meta: [$node->getKind() === NodeKind::Collection ? 'список' : 'сторінка'],
            );
        }

        if ($rows === []) {
            return $this->page->renderNothingChosen();
        }

        return $this->page->renderRows(
            new ContentRows('Що відкрити?', $rows, count($rows), 1, count($rows)),
            '',
        );
    }

    private function grid(string $ref, string $source, int $pageNumber): string
    {
        $collection = $source === '' ? null : $this->surfaces->collection($source);

        if ($collection === null) {
            return $this->page->renderMissing($ref);
        }

        $rows = $collection->rows(ContentSurfaceRegistry::filtersOf($source), $pageNumber, self::PER_PAGE);

        return $this->page->renderRows($rows, $ref);
    }

    private function csrfToken(): string
    {
        if (!isset($this->session)) {
            return '';
        }

        /** @var CsrfToken $token */
        $token = $this->session->getPayload(CsrfToken::class);

        return $token->getValue();
    }
}
