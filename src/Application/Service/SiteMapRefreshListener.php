<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Contract\SiteMapProviderInterface;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Event\EventExecution;
use Semitexa\Orm\Domain\Event\ResourceChangedEvent;

/**
 * Keeps the map in step with the content, so nobody has to remember to rebuild it.
 *
 * A skill publishes an event, an editor renames a page — the write lands in the
 * module's own table and the ORM announces it. If that table is one the site's
 * map is built from, the map is re-projected: a new section appears, a count
 * moves, a renamed page carries its new title. The alternative is a map that
 * is right on the day it was built and quietly wrong afterwards, which is worse
 * than no map at all.
 *
 * Re-projection is a dozen upserts keyed by record, so doing it again costs
 * little and changes nothing that has not actually moved. The per-execution
 * guard exists for the other case: an import writing five hundred rows would
 * otherwise rebuild the same map five hundred times.
 */
#[AsEventListener(event: ResourceChangedEvent::class, execution: EventExecution::Sync)]
#[ExecutionScoped]
final class SiteMapRefreshListener
{
    #[InjectAsReadonly]
    protected SiteMapProjector $projector;

    /** @var array<string, true> sites already refreshed in this execution */
    private array $refreshed = [];

    public function handle(ResourceChangedEvent $event): void
    {
        if (!isset($this->projector)) {
            return;
        }

        foreach ($this->providersFor($event->resourceKey) as $provider) {
            $siteRef = $provider->siteRef();
            if (isset($this->refreshed[$siteRef])) {
                continue;
            }

            $this->refreshed[$siteRef] = true;

            try {
                $this->projector->project($provider);
            } catch (\Throwable) {
                // The write already happened and is the truth; a map that
                // failed to follow is repaired by the next change or by
                // `cms:map:build`. Never let bookkeeping undo content.
            }
        }
    }

    /**
     * @return list<SiteMapProviderInterface>
     */
    private function providersFor(string $resourceKey): array
    {
        $matching = [];

        foreach ($this->projector->providers() as $provider) {
            if (in_array($resourceKey, $provider->watches(), true)) {
                $matching[] = $provider;
            }
        }

        return $matching;
    }
}
