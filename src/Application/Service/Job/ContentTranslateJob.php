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
    // Five minutes suits a museum whose admins edit a page at a time; a site
    // with a busy newsroom will want it rarer, so the install decides.
    cronExpression: 'env::CMS_TRANSLATE_CRON::*/5 * * * *',
    overlapPolicy: 'skip',
    // Per tenant, because the queue is: each site's rows are only visible from
    // inside its own context. A global run would drain the default tenant, find
    // nothing, report success, and leave every site's translations pending —
    // the worst shape of failure, because it looks like it works.
    tenantMode: 'per_tenant',
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
