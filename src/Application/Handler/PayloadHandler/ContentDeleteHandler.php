<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Handler\PayloadHandler;

use Semitexa\Cms\Application\Payload\Request\ContentDeletePayload;
use Semitexa\Cms\Application\Service\ContentAnswerPage;
use Semitexa\Cms\Application\Service\ContentSurfaceRegistry;
use Semitexa\Cms\Domain\Contract\ContentRemoverInterface;
use Semitexa\Cms\Domain\Model\ContentRow;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;

/**
 * Takes one record off the site.
 *
 * Two rules decide everything here.
 *
 * A PLACE ON THE MAP IS NOT REMOVABLE THIS WAY. The map is authored — a module
 * says which of its records are places — so removing one here would leave the
 * map claiming a record that no longer exists, and the next projection would
 * report it stale rather than gone. A page stops being a place when its module
 * stops listing it, which is a code change, not a button.
 *
 * THE FIRST POST NEVER REMOVES. It answers with a page naming what is about to
 * go; only a second, deliberate post carries the confirmation. A row's delete
 * control sits next to its title in a list, which is exactly where a misclick
 * lives.
 */
#[AsPayloadHandler(payload: ContentDeletePayload::class, resource: ResourceResponse::class)]
final class ContentDeleteHandler implements TypedHandlerInterface
{
    private const PER_PAGE = 25;

    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    #[InjectAsReadonly]
    protected ContentSurfaceRegistry $surfaces;

    #[InjectAsReadonly]
    protected ContentAnswerPage $answers;

    public function handle(ContentDeletePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $ref = trim($payload->getRef());
        $collectionRef = trim($payload->getCollection());

        if ($this->graph->nodeByRef($ref) !== null) {
            return $this->html($resource, $this->answers->renderCannotRemove(
                $ref,
                'Це сторінка на карті сайту. Її прибирає модуль, який її туди поставив.',
            ));
        }

        $properties = $this->graph->nodeByRef($collectionRef)?->getProperties() ?? [];
        if (($properties['opens'] ?? null) !== 'grid') {
            return $this->html($resource, $this->answers->renderMissing($collectionRef));
        }

        $source = (string) ($properties['source'] ?? '');
        $collection = $source === '' ? null : $this->surfaces->collection($source);

        if (!$collection instanceof ContentRemoverInterface) {
            return $this->html($resource, $this->answers->renderCannotRemove(
                $ref,
                'Цей список веде модуль, який не дозволяє прибирати записи звідси.',
            ));
        }

        $filters = ContentSurfaceRegistry::filtersOf($source);

        if (!$payload->isConfirmed()) {
            return $this->html($resource, $this->answers->renderConfirmRemoval(
                $ref,
                $this->titleOf($collection, $filters, $ref) ?? $ref,
                $collectionRef,
                $this->csrfToken(),
            ));
        }

        try {
            $collection->remove($ref);
        } catch (\RuntimeException $e) {
            return $this->html($resource, $this->answers->renderCannotRemove($ref, $e->getMessage()));
        }

        // Back to the list it came from, which is also the proof: the row is
        // gone from the same screen the author was looking at.
        return $this->html($resource, $this->answers->renderRows(
            $collection->rows($filters, 1, self::PER_PAGE),
            $collectionRef,
            $this->csrfToken(),
            $collection instanceof \Semitexa\Cms\Domain\Contract\ContentCreatorInterface,
            true,
        ));
    }

    /**
     * The record's title, so the confirmation names a thing rather than a ref.
     *
     * @param array<string, string> $filters
     */
    private function titleOf(object $collection, array $filters, string $ref): ?string
    {
        if (!method_exists($collection, 'rows')) {
            return null;
        }

        foreach ($collection->rows($filters, 1, self::PER_PAGE)->rows as $row) {
            if ($row instanceof ContentRow && $row->ref === $ref && $row->title !== '') {
                return $row->title;
            }
        }

        return null;
    }

    private function html(ResourceResponse $resource, string $html): ResourceResponse
    {
        return $resource
            ->setContent($html)
            ->setHeader('Content-Type', 'text/html; charset=UTF-8');
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
