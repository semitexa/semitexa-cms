<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service\Job;

use Semitexa\Cms\Application\Service\TranslationDrain;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Scheduler\Attribute\AsScheduledJob;
use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;

/**
 * Drains the translation queue on a short tick.
 *
 * Every five minutes, not because that is when translation is due — the
 * debounce decides that — but so the wait after an admin stops typing is close
 * to the window they were promised rather than the cron's idea of soon.
 *
 * An install with no scheduler runs `cms:translate:drain` from cron instead;
 * both go through the same drain.
 */
#[AsService]
#[AsScheduledJob(
    key: 'cms.translate',
    cronExpression: '*/5 * * * *',
    overlapPolicy: 'skip',
)]
final class ContentTranslateJob implements ScheduledJobInterface
{
    /** Model calls per tick: a burst of edits drains over a few ticks instead of stalling one. */
    private const BATCH = 10;

    #[InjectAsReadonly]
    protected TranslationDrain $drain;

    public function handle(ScheduledJobContext $context): void
    {
        $this->drain->drain(self::BATCH);
    }
}
