<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Contract\ContentTranslatorInterface;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;

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

    /**
     * @return array{done: int, failed: int, gone: int}
     */
    public function drain(int $batch = 10): array
    {
        $done = 0;
        $failed = 0;
        $gone = 0;

        foreach ($this->queue->due($batch) as $task) {
            $translator = $this->surfaces->translator($task->translatorId);

            if ($translator === null) {
                // The module that owned this ref is no longer installed for this
                // tenant. Keeping the row would retry forever against nothing.
                $this->queue->forget($task->ref);
                $gone++;
                continue;
            }

            // Re-read the text now rather than trusting the hash stored when the
            // save happened: an edit may have landed since, and translating the
            // older text would ship a version nobody asked for.
            $hash = $translator->fingerprint($task->ref);

            if ($hash === null) {
                $this->queue->forget($task->ref);
                $gone++;
                continue;
            }

            // Stamp what we are about to translate as the row's current text.
            // The stored hash was written when the save happened and may no
            // longer describe the same thing — the record was edited again, or
            // the module changed what it considers translatable. Settling
            // against a hash nobody recognises leaves the row due forever,
            // re-translating the same text on every tick.
            $this->queue->enqueue($task->ref, $task->translatorId, $hash, 0);

            try {
                $translator->translate($task->ref);
                $this->queue->settle($task->ref, $hash);
                $done++;
            } catch (\Throwable $e) {
                $this->queue->fail($task->ref, $e->getMessage());
                $failed++;
            }
        }

        return ['done' => $done, 'failed' => $failed, 'gone' => $gone];
    }
}
