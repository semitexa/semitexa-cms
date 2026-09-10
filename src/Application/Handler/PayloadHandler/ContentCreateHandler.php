<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Handler\PayloadHandler;

use Semitexa\Cms\Application\Payload\Request\ContentCreatePayload;
use Semitexa\Cms\Application\Service\ContentEditorPage;
use Semitexa\Cms\Application\Service\ContentSurfaceRegistry;
use Semitexa\Cms\Domain\Contract\ContentCreatorInterface;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;

/**
 * Starts a new item in a collection and opens it.
 *
 * Publishing did not require a developer for the words, only for the existence
 * of the thing being worded — an author could edit every page of a site and not
 * add one. This is the missing half.
 *
 * The collection is resolved through the graph for the same reason the editor
 * is: only a place the map actually carries can be written into, so a ref typed
 * into a form is not a way to reach another site's records. The MODULE mints
 * the ref, and the record opens immediately after — a create that leaves the
 * author back on the list makes them hunt for what they just made.
 */
#[AsPayloadHandler(payload: ContentCreatePayload::class, resource: ResourceResponse::class)]
final class ContentCreateHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    #[InjectAsReadonly]
    protected ContentSurfaceRegistry $surfaces;

    #[InjectAsReadonly]
    protected ContentEditorPage $page;

    public function handle(ContentCreatePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $ref = trim($payload->getRef());
        $properties = $this->graph->nodeByRef($ref)?->getProperties() ?? [];

        if (($properties['opens'] ?? null) !== 'grid') {
            return $this->html($resource, $this->page->renderMissing($ref));
        }

        $source = (string) ($properties['source'] ?? '');
        $collection = $source === '' ? null : $this->surfaces->collection($source);

        if (!$collection instanceof ContentCreatorInterface) {
            // The module lists these records and does not author them. Saying so
            // beats a blank editor the author cannot save.
            return $this->html($resource, $this->page->renderCannotCreate($ref));
        }

        try {
            // The collection's own filters go in: a list filled from ?type=event
            // starts an EVENT, not a bare record the list would then refuse to
            // show back.
            $created = trim($collection->create(ContentSurfaceRegistry::filtersOf($source)));
        } catch (\RuntimeException $e) {
            return $this->html($resource, $this->page->renderCreateFailed($ref, $e->getMessage()));
        }

        if ($created === '') {
            return $this->html($resource, $this->page->renderCreateFailed($ref, 'The module created a record without a ref.'));
        }

        $draft = $this->surfaces->editor((string) ($properties['editor'] ?? ''))?->load($created);
        foreach ($draft === null ? $this->surfaces->editors() : [] as $editor) {
            $draft = $editor->load($created);
            if ($draft !== null) {
                break;
            }
        }

        return $this->html(
            $resource,
            $draft === null
                // Created, but nothing can open it. Not silent: the record
                // exists and the author has to be told where it went.
                ? $this->page->renderCreateFailed($created, 'The record was created, but no editor answers for it.')
                : $this->page->render($draft, $this->csrfToken()),
        );
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
