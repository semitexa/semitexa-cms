<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service\Job;

use Semitexa\Cms\Application\Service\SeoDrain;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Scheduler\Attribute\AsScheduledJob;
use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;

/**
 * Drains the metadata queue on a short tick.
 *
 * The tick is not when metadata is due — the debounce decides that. It is how
 * closely the wait after an admin stops typing matches the window they were
 * promised, rather than the cron's idea of soon.
 *
 * An install with no scheduler runs `cms:seo:drain` from cron instead; both go
 * through the same drain.
 */
#[AsService]
#[AsScheduledJob(
    key: 'cms.seo',
    cronExpression: 'env::CMS_SEO_CRON::*/5 * * * *',
    overlapPolicy: 'skip',
    // Per tenant, because the rows are: each site's pages are only visible from
    // inside its own context. A global run would drain the default tenant, find
    // nothing, report success, and leave every site's metadata pending — the
    // worst shape of failure, because it looks like it works.
    tenantMode: 'per_tenant',
)]
final class ContentSeoJob implements ScheduledJobInterface
{
    /** Model calls per tick: a burst of edits drains over a few ticks instead of stalling one. */
    private const BATCH = 10;

    #[InjectAsReadonly]
    protected SeoDrain $drain;

    public function handle(ScheduledJobContext $context): void
    {
        $this->drain->drain(self::BATCH);
    }
}
