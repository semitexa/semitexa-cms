<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Handler\PayloadHandler;

use Semitexa\Cms\Application\Payload\Request\ContentSavePayload;
use Semitexa\Cms\Application\Service\ContentAnswerPage;
use Semitexa\Cms\Application\Service\ContentEditorPage;
use Semitexa\Cms\Application\Service\ContentHtmlSanitizer;
use Semitexa\Cms\Application\Service\ContentSurfaceRegistry;
use Semitexa\Cms\Application\Service\SeoDrain;
use Semitexa\Cms\Application\Service\SeoStore;
use Semitexa\Cms\Application\Service\TranslationQueue;
use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;
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
    protected ContentAnswerPage $answers;

    #[InjectAsReadonly]
    protected TranslationQueue $translations;

    #[InjectAsReadonly]
    protected SeoStore $seo;

    /** Only for its fingerprint — the drain, not the writing, happens later. */
    #[InjectAsReadonly]
    protected SeoDrain $seoDrain;

    #[InjectAsReadonly]
    protected ContentHtmlSanitizer $sanitizer;

    public function handle(ContentSavePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $ref = trim($payload->getRef());
        $node = $ref === '' ? null : $this->graph->nodeByRef($ref);
        $declaredEditor = $node?->getProperties()['editor'] ?? null;
        $editorId = is_string($declaredEditor) ? $declaredEditor : '';
        $editor = $editorId === '' ? $this->editorForRef($ref) : $this->surfaces->editor($editorId);

        if ($editor === null) {
            return $this->html($resource, $this->answers->renderMissing($ref));
        }

        // What the record looks like BEFORE the write, because that is the only
        // place the field kinds are declared — and the kinds decide which
        // submitted values are markup. A ref that names nothing has no fields
        // to judge by, so there is nothing to sanitise against and nothing to
        // save into: fail closed rather than write unexamined markup.
        $before = $editor->load($ref);
        if ($before === null) {
            return $this->html($resource, $this->answers->renderMissing($ref));
        }

        // Nothing in the browser enforces `required` on these fields: the rich
        // one is a hidden input, which is barred from constraint validation,
        // and a form can be posted without ever loading our page anyway. This
        // is the gate.
        $error = self::missingRequired($before, $payload->submittedValues())
            ?? self::malformedDate($before, $payload->submittedValues());
        $warning = null;

        // Whether the author's text reached the record. Everything after that
        // write — the metadata, the translation and SEO queues — is separate
        // work on a store this handler cannot enrol in one transaction with the
        // content. So a failure there must not be reported as a failed save:
        // the text IS saved, and telling the author it is not sends them back
        // to retype what is already there, or to hunt for damage that does not
        // exist.
        $contentSaved = false;

        try {
            if ($error === null) {
                $clean = $this->sanitizer->sanitizeValues(
                    $payload->submittedValues(),
                    self::htmlFieldNames($before),
                );
                $editor->save($ref, $clean->values);
                $contentSaved = true;
                // What the allowlist would not take. Said here rather than
                // swallowed: an article emptied of its pictures under
                // «Збережено.» is discovered by reopening it, which is the
                // worst moment and the wrong person to discover it.
                $warning = $clean->notice();
                $this->saveSeo($ref, $editor->editorId(), $payload->submittedSeo());
                $this->queueTranslation($ref, $editor->editorId());
                $saved = $editor->load($ref);
                if ($saved !== null) {
                    $this->queueSeo($ref, $editor->editorId(), $saved);
                }
            }
        } catch (\InvalidArgumentException $e) {
            [$error, $warning] = self::reportFailure($contentSaved, $warning, $e->getMessage(), $e->getMessage());
        } catch (\Throwable) {
            [$error, $warning] = self::reportFailure(
                $contentSaved,
                $warning,
                'Не вдалося зберегти. Спробуйте ще раз.',
                'Текст збережено, але метадані для пошуку — ні. Відкрийте запис і збережіть ще раз.',
            );
        }

        // Reload rather than echo the submitted values back: what the record
        // now holds is the only honest thing to show, and a normalised title or
        // a generated slug would otherwise be invisible until the next open.
        $draft = $editor->load($ref);

        if ($draft === null) {
            return $this->html($resource, $this->answers->renderMissing($ref));
        }

        return $this->html($resource, $this->page->render(
            $draft,
            $this->csrfToken(),
            $error === null ? 'Збережено.' : null,
            $error,
            $warning,
        ));
    }

    /**
     * Where a failure belongs once the content is already in the record.
     *
     * The author's text is written first; the metadata, the translation queue
     * and the SEO queue are separate work on a store this handler cannot enrol
     * in one transaction with it. So the two halves fail differently and must
     * be reported differently. Before the write, a failure means nothing was
     * saved and the page says so. After it, the text IS saved — reporting that
     * as «Не вдалося зберегти» sends the author back to retype what is already
     * there, or to go looking for damage that does not exist, and the next
     * thing they do is save again over their own good copy.
     *
     * @return array{?string, ?string} the error to show, and the warning
     */
    private static function reportFailure(
        bool $contentSaved,
        ?string $warning,
        string $beforeTheWrite,
        string $afterTheWrite,
    ): array {
        return $contentSaved
            ? [null, self::alsoSay($warning, $afterTheWrite)]
            : [$beforeTheWrite, $warning];
    }

    /**
     * Add a second thing worth saying to the notice, keeping the first.
     *
     * Both halves matter and neither replaces the other: the pictures an
     * allowlist refused and the metadata that did not save are separate facts
     * about one save, and dropping either is how an author finds out later.
     */
    private static function alsoSay(?string $notice, string $addition): string
    {
        return $notice === null || $notice === '' ? $addition : $notice . ' ' . $addition;
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
     * Take the metadata an author typed, and only that.
     *
     * The panel posts every metadata field on every save, so most of what
     * arrives here is blanks that mean nothing. Which blanks mean something is
     * a question only the stored record can answer, and
     * {@see \Semitexa\Cms\Domain\Model\ContentSeo::editorSubmission()}
     * answers it: a blank on a field the author owns hands it back to the
     * generator, a blank on one they do not own is dropped.
     *
     * A submission carrying no metadata group at all is left alone entirely: an
     * editor that never offered the fields is not an author clearing them.
     *
     * @param array<string, string> $values
     */
    private function saveSeo(string $ref, string $editorId, array $values): void
    {
        if ($values === [] || !isset($this->seo)) {
            return;
        }

        // Through the record, because a blank box means different things
        // depending on who owns the field — see ContentSeo::editorSubmission().
        $this->seo->saveAuthored($ref, $editorId, $this->seo->get($ref)->editorSubmission($values));
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

    /**
     * The first required field the submission left empty, as a message.
     *
     * A required field absent from the submission counts as empty: the dialog
     * posts every field it renders, so a missing name is a caller that decided
     * not to send one, not a partial edit we should merge.
     *
     * @param array<string, string> $values
     */
    /**
     * The first date field holding something that is not a calendar day.
     *
     * A native date input cannot produce one, which is exactly why this is
     * here: the browser is not the boundary. A form can be posted from
     * anywhere, and a module that stored «31.02.2026» would find out when
     * something tried to render a schedule from it, months later and far from
     * the save that caused it.
     *
     * Reported rather than corrected. There is no honest correction for a day
     * that does not exist — picking a nearby one puts a date on the page that
     * nobody chose.
     *
     * @param array<string, string> $values
     */
    private static function malformedDate(ContentDraft $draft, array $values): ?string
    {
        foreach ($draft->fields as $field) {
            if ($field->kind !== ContentField::DATE) {
                continue;
            }

            $submitted = $values[$field->name] ?? '';

            // Nothing entered at all — the exact empty string, which is what a
            // date input submits when it is blank and what a missing field
            // resolves to. NOT a trimmed test: «   » is something the record
            // would actually store, and skipping it here would put whitespace
            // in a date column through the very gate that exists to stop it.
            // Whether an empty value is allowed is missingRequired()'s
            // question, not this one.
            if ($submitted === '') {
                continue;
            }

            // Checked exactly as it will be STORED. Validating a trimmed copy
            // while saving the original is how « 2026-09-11 » passed this gate
            // and landed in the record with its spaces — where isCalendarDay(),
            // the very same test, calls it malformed on the way back out.
            if (!ContentField::isCalendarDay($submitted)) {
                return 'Поле «' . $field->label . '»: «' . $submitted . '» — не дата. Формат: РРРР-ММ-ДД.';
            }
        }

        return null;
    }

    private static function missingRequired(ContentDraft $draft, array $values): ?string
    {
        foreach ($draft->fields as $field) {
            if (!$field->required) {
                continue;
            }

            if (!self::hasContent($values[$field->name] ?? '', $field->kind)) {
                return 'Заповніть поле «' . $field->label . '».';
            }
        }

        return null;
    }

    /**
     * Whether a submitted value counts as filled in.
     *
     * Markup is judged by what it would show, not by its length: an empty Trix
     * document still posts `<div><br></div>`, and `&nbsp;` is not text an
     * author meant to write. A picture alone IS content, which is why an
     * <img> counts even with no words around it.
     */
    private static function hasContent(string $value, string $kind): bool
    {
        if ($kind !== ContentField::HTML) {
            return trim($value) !== '';
        }

        if (stripos($value, '<img') !== false) {
            return true;
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $text)) !== '';
    }

    /**
     * Names of the fields this editor declares as markup.
     *
     * Only these are sanitised: a LINE or TEXT field is plain text, and putting
     * markup rules over it would eat a `<` an author legitimately typed.
     *
     * @return list<string>
     */
    private static function htmlFieldNames(ContentDraft $draft): array
    {
        $names = [];
        foreach ($draft->fields as $field) {
            if ($field->kind === ContentField::HTML) {
                $names[] = $field->name;
            }
        }

        return $names;
    }
}
