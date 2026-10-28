<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Cms\Domain\Contract\ContentOverlayTranslatorInterface;
use Semitexa\Cms\Domain\Model\TranslatedValue;

/**
 * Runs the translations whose debounce window has closed.
 *
 * Shared by the scheduled job and the console command so an install without a
 * scheduler is not a second implementation: same batch, same settle rules.
 */
#[AsService]
final class TranslationDrain
{
    #[InjectAsReadonly]
    protected TranslationQueue $queue;

    #[InjectAsReadonly]
    protected ContentSurfaceRegistry $surfaces;

    #[InjectAsReadonly]
    protected ContentHtmlSanitizer $sanitizer;

    /**
     * @return array{done: int, failed: int, gone: int}
     */
    public function drain(int $batch = 10): array
    {
        $done = 0;
        $failed = 0;
        $gone = 0;

        foreach ($this->queue->due($batch) as $task) {
            $translator = $this->surfaces->translator($task->getTranslatorId());

            if ($translator === null) {
                // The module that owned this ref is no longer installed for this
                // tenant. Keeping the row would retry forever against nothing.
                $this->queue->forget($task->getRef());
                $gone++;
                continue;
            }

            // Re-read the text now rather than trusting the hash stored when the
            // save happened: an edit may have landed since, and translating the
            // older text would ship a version nobody asked for.
            $hash = $translator->fingerprint($task->getRef());

            if ($hash === null) {
                $this->queue->forget($task->getRef());
                $gone++;
                continue;
            }

            // Stamp what we are about to translate as the row's current text.
            // The stored hash was written when the save happened and may no
            // longer describe the same thing — the record was edited again, or
            // the module changed what it considers translatable. Settling
            // against a hash nobody recognises leaves the row due forever,
            // re-translating the same text on every tick.
            $this->queue->enqueue($task->getRef(), $task->getTranslatorId(), $hash, 0);

            try {
                if ($translator instanceof ContentOverlayTranslatorInterface) {
                    // What the model wrote is untrusted: sanitised here, where
                    // the CMS sees it, not left to every translator to remember.
                    $translator->storeTranslations($task->getRef(), $this->sanitizedTranslations($translator, $task->getRef()));
                } else {
                    $translator->translate($task->getRef());
                }
                $this->queue->settle($task->getRef(), $hash);
                $done++;
            } catch (\Throwable $e) {
                $this->queue->fail($task->getRef(), $e->getMessage());
                $failed++;
            }
        }

        return ['done' => $done, 'failed' => $failed, 'gone' => $gone];
    }

    /** @return list<TranslatedValue> what the model wrote for $ref, its markup made safe */
    private function sanitizedTranslations(ContentOverlayTranslatorInterface $translator, string $ref): array
    {
        return array_map(
            fn (TranslatedValue $v): TranslatedValue => $v->html ? $v->withValue($this->sanitizer->sanitize($v->value)) : $v,
            $translator->translations($ref),
        );
    }
}
