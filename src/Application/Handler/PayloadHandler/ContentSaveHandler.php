<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Handler\PayloadHandler;

use Semitexa\Cms\Application\Payload\Request\ContentSavePayload;
use Semitexa\Cms\Application\Service\ContentEditorPage;
use Semitexa\Cms\Application\Service\ContentSurfaceRegistry;
use Semitexa\Cms\Application\Service\SeoDrain;
use Semitexa\Cms\Application\Service\SeoStore;
use Semitexa\Cms\Application\Service\TranslationQueue;
use Semitexa\Cms\Domain\Model\ContentDraft;
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
    protected ContentSurfaceRegistry $surfaces;

    #[InjectAsReadonly]
    protected ContentEditorPage $page;

    #[InjectAsReadonly]
    protected TranslationQueue $translations;

    #[InjectAsReadonly]
    protected SeoStore $seo;

    /** Only for its fingerprint — the drain, not the writing, happens later. */
    #[InjectAsReadonly]
    protected SeoDrain $seoDrain;

    public function handle(ContentSavePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $ref = trim($payload->getRef());
        $node = $ref === '' ? null : $this->graph->nodeByRef($ref);
        $editorId = is_string($node?->properties['editor'] ?? null) ? (string) $node->properties['editor'] : '';
        $editor = $editorId === '' ? $this->editorForRef($ref) : $this->surfaces->editor($editorId);

        if ($editor === null) {
            return $this->html($resource, $this->page->renderMissing($ref));
        }

        $error = null;
        try {
            $editor->save($ref, $payload->submittedValues());
            $this->queueTranslation($ref, $editor->editorId());
            $saved = $editor->load($ref);
            if ($saved !== null) {
                $this->queueSeo($ref, $editor->editorId(), $saved);
            }
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

    /**
     * Rows of a collection have no node on the map, so the editors themselves
     * say which of them owns the ref — each is tenant-scoped and only loads what
     * it recognises.
     */
    private function editorForRef(string $ref): ?\Semitexa\Cms\Domain\Contract\ContentEditorInterface
    {
        if ($ref === '') {
            return null;
        }

        foreach ($this->surfaces->editors() as $editor) {
            if ($editor->load($ref) !== null) {
                return $editor;
            }
        }

        return null;
    }

    /**
     * Park the record for translation instead of translating here.
     *
     * People edit in bursts — save, reread, fix a word — and a model call on
     * every save would spend ten of them on one paragraph and make each save
     * wait seconds for the answer. The queue's window collapses that into one
     * translation of the text they actually settled on.
     */
    private function queueTranslation(string $ref, string $editorId): void
    {
        if (!isset($this->translations)) {
            return;
        }

        $translator = $this->surfaces->translator($editorId);
        if ($translator === null) {
            return; // this module keeps no other languages
        }

        $hash = $translator->fingerprint($ref);
        if ($hash !== null) {
            $this->translations->enqueue($ref, $editorId, $hash);
        }
    }

    /**
     * Park the page for a metadata rewrite — deferred, never on the write.
     *
     * Same reasoning as the translation queue beside it, and the same window:
     * an editor fixing a comma five times in a row would otherwise buy five
     * descriptions of five nearly identical pages, of which only the last was
     * ever read. The deadline slides with each save, so the model sees the text
     * they settled on.
     */
    private function queueSeo(string $ref, string $editorId, ContentDraft $draft): void
    {
        if (!isset($this->seo) || !isset($this->seoDrain)) {
            return;
        }

        $this->seo->touch($ref, $editorId, $this->seoDrain->fingerprint($draft));
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
