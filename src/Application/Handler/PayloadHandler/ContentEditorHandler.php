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
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;

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

    public function handle(ContentEditorPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $ref = trim($payload->getRef());
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

        return $resource
            ->setContent($html)
            ->setHeader('Content-Type', 'text/html; charset=UTF-8');
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
        if ($ref === '') {
            return $this->page->renderMissing($ref);
        }

        foreach ($this->surfaces->editors() as $editor) {
            $draft = $editor->load($ref);
            if ($draft !== null) {
                return $this->page->render($draft, $this->csrfToken());
            }
        }

        return $this->page->renderMissing($ref);
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
