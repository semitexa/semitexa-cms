<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Handler\PayloadHandler;

use Semitexa\Cms\Application\Payload\Request\ContentEditorPayload;
use Semitexa\Cms\Application\Service\ContentEditorPage;
use Semitexa\Cms\Application\Service\ContentEditorRegistry;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;

/**
 * Opens the editor for a place on the map.
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
    protected ContentEditorRegistry $editors;

    #[InjectAsReadonly]
    protected ContentEditorPage $page;

    public function handle(ContentEditorPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $ref = trim($payload->getRef());
        $node = $ref === '' ? null : $this->graph->nodeByRef($ref);
        $editorId = is_string($node?->properties['editor'] ?? null) ? (string) $node->properties['editor'] : '';

        $draft = $editorId === '' ? null : $this->editors->find($editorId)?->load($ref);

        return $resource
            ->setContent($draft === null
                ? $this->page->renderMissing($ref)
                : $this->page->render($draft, $this->csrfToken()))
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
