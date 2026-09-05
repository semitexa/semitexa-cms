<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;

/**
 * Writes the metadata for the pages whose debounce window has closed.
 *
 * Shared by the scheduled job and the console command, so an install without a
 * scheduler is not a second implementation — same batch, same settle rules as
 * {@see TranslationDrain}, which this is modelled on.
 */
#[AsService]
final class SeoDrain
{
    #[InjectAsReadonly]
    protected SeoStore $store;

    #[InjectAsReadonly]
    protected ContentSurfaceRegistry $surfaces;

    #[InjectAsReadonly]
    protected SeoWriter $writer;

    /**
     * @return array{done: int, failed: int, gone: int, skipped: int}
     */
    public function drain(int $batch = 10): array
    {
        $done = 0;
        $failed = 0;
        $gone = 0;
        $skipped = 0;

        foreach ($this->store->due($batch) as $row) {
            $editor = $this->surfaces->editor($row->editorId);
            if ($editor === null) {
                // The module that owned this ref is no longer installed for this
                // tenant. Keeping the row would retry forever against nothing.
                $this->store->forget($row->ref);
                $gone++;
                continue;
            }

            // Re-read the page NOW rather than trusting the hash stored when the
            // save happened: more edits may have landed inside the window, and
            // describing the older text would publish a version nobody wrote.
            $draft = $editor->load($row->ref);
            if ($draft === null) {
                $this->store->forget($row->ref);
                $gone++;
                continue;
            }

            $seo = $this->store->get($row->ref);
            $hash = $this->fingerprint($draft);

            if (!$seo->isEmpty() && !$seo->isStale($hash)) {
                // Described from exactly this text already — the window closed
                // on an edit that undid itself. Settle for nothing.
                $this->store->settle($seo, $row->editorId, $hash);
                $skipped++;
                continue;
            }

            try {
                $written = $this->writer->write($seo, $draft, $hash);
                $this->store->settle($written, $row->editorId, $this->fingerprint($editor->load($row->ref) ?? $draft));
                $done++;
            } catch (\Throwable $e) {
                $this->store->fail($row->ref, $e->getMessage());
                $failed++;
            }
        }

        return ['done' => $done, 'failed' => $failed, 'gone' => $gone, 'skipped' => $skipped];
    }

    /**
     * What the metadata was written from. Only the values matter — a relabelled
     * field is the same page to a reader, and should not buy a model call.
     */
    public function fingerprint(\Semitexa\Cms\Domain\Model\ContentDraft $draft): string
    {
        $parts = [$draft->title];
        foreach ($draft->fields as $field) {
            $parts[] = $field->name . "\0" . $field->value;
        }

        return hash('sha256', implode("\1", $parts));
    }
}
