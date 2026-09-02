<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Handler\PayloadHandler;

use Semitexa\Cms\Application\Payload\Request\ContentSavePayload;
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
 * Writes an edit back to the module that owns the record.
 *
 * Through the module's repository, never into the graph: the table is what
 * renders the public site, and the map follows it (the ORM's change event
 * re-projects the place). Re-renders the form afterwards rather than
 * redirecting, because this lives in a dialog — a redirect would navigate the
 * iframe somewhere the console does not expect.
 */
#[AsPayloadHandler(payload: ContentSavePayload::class, resource: ResourceResponse::class)]
final class ContentSaveHandler implements TypedHandlerInterface
{
    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    #[InjectAsReadonly]
    protected ContentEditorRegistry $editors;

    #[InjectAsReadonly]
    protected ContentEditorPage $page;

    public function handle(ContentSavePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $ref = trim($payload->getRef());
        $node = $ref === '' ? null : $this->graph->nodeByRef($ref);
        $editorId = is_string($node?->properties['editor'] ?? null) ? (string) $node->properties['editor'] : '';
        $editor = $editorId === '' ? null : $this->editors->find($editorId);

        if ($editor === null) {
            return $this->html($resource, $this->page->renderMissing($ref));
        }

        $error = null;
        try {
            $editor->save($ref, $payload->submittedValues());
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (\Throwable) {
            $error = 'Не вдалося зберегти. Спробуйте ще раз.';
        }

        // Reload rather than echo the submitted values back: what the record
        // now holds is the only honest thing to show, and a normalised title or
        // a generated slug would otherwise be invisible until the next open.
        $draft = $editor->load($ref);

        if ($draft === null) {
            return $this->html($resource, $this->page->renderMissing($ref));
        }

        return $this->html($resource, $this->page->render(
            $draft,
            $this->csrfToken(),
            $error === null ? 'Збережено.' : null,
            $error,
        ));
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
