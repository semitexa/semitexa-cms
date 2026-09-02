<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Attribute\AsSiteMap;
use Semitexa\Cms\Domain\Contract\SiteMapProviderInterface;
use Semitexa\Cms\Domain\Model\Place;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Relation;

/**
 * Writes a site's map into the graph.
 *
 * The projection is one-way and the graph is not the truth: pages and events
 * stay in the module's own tables, which is what renders the public site. What
 * lands here is a map of *places* — which is why a rebuild must never flatten
 * what a person has since done to it.
 *
 * Hence two rules. Identity is the record's ref, so renaming a place on the map
 * keeps it the same node. And properties merge rather than replace, so a
 * rebuild after new content appears does not undo a title someone corrected or
 * an order they chose.
 */
#[AsService]
final class SiteMapProjector
{
    public const SOURCE = 'cms:map';

    #[InjectAsReadonly]
    protected GraphStoreInterface $graph;

    /**
     * Project every discovered map.
     *
     * @return list<array{site: string, places: int, edges: int, stale: list<string>}>
     */
    public function projectAll(bool $dryRun = false): array
    {
        $reports = [];

        foreach ($this->providers() as $provider) {
            $reports[] = $this->project($provider, $dryRun);
        }

        return $reports;
    }

    /**
     * @return array{site: string, places: int, edges: int, stale: list<string>}
     */
    public function project(SiteMapProviderInterface $provider, bool $dryRun = false): array
    {
        $siteRef = trim($provider->siteRef());
        $places = [];
        foreach ($provider->places() as $place) {
            $places[$place->ref] = $place;
        }

        if ($dryRun) {
            return [
                'site' => $siteRef,
                'places' => count($places),
                'edges' => count(array_filter($places, static fn (Place $p): bool => $p->parentRef !== null)),
                'stale' => $this->staleRefs($siteRef, array_keys($places)),
            ];
        }

        $site = $this->graph->upsertNodeByRef(
            NodeKind::Site,
            $siteRef,
            $provider->siteTitle(),
            ['origin' => 'site', 'opens' => 'map'],
            self::SOURCE,
        );

        $ids = [$siteRef => $site->id];

        foreach ($places as $place) {
            $node = $this->graph->upsertNodeByRef(
                $place->kind,
                $place->ref,
                $place->title,
                $place->nodeProperties(),
                self::SOURCE,
            );
            $ids[$place->ref] = $node->id;
        }

        $edges = 0;
        foreach ($places as $place) {
            $parentRef = $place->parentRef ?? $siteRef;
            $parentId = $ids[$parentRef] ?? null;

            // A place naming a parent that no map produced would otherwise hang
            // off nothing and vanish from every view that walks from the root.
            if ($parentId === null) {
                $parentId = $ids[$siteRef];
            }

            // Parent → child, matching the direction the OS graph already uses
            // for PART_OF.
            $this->graph->addEdge($parentId, $ids[$place->ref], Relation::PART_OF, 100, self::SOURCE);
            $edges++;
        }

        return [
            'site' => $siteRef,
            'places' => count($places),
            'edges' => $edges,
            'stale' => $this->staleRefs($siteRef, array_keys($places)),
        ];
    }

    /**
     * Places this projector once created that the map no longer claims.
     *
     * Reported, never deleted: a node may have been kept deliberately, and a
     * rebuild silently removing part of someone's map is the one behaviour that
     * would stop them trusting it.
     *
     * @param list<string> $currentRefs
     * @return list<string>
     */
    public function staleRefs(string $siteRef, array $currentRefs): array
    {
        $known = array_flip($currentRefs);
        $stale = [];

        foreach ($this->graph->graph(1000, [NodeKind::Page, NodeKind::Collection])['nodes'] as $node) {
            $ref = $node->ref;
            if ($ref === null || $node->source !== self::SOURCE) {
                continue;
            }
            if (!str_starts_with($ref, $siteRef . ':')) {
                continue;
            }
            if (!isset($known[$ref])) {
                $stale[] = $ref;
            }
        }

        return $stale;
    }

    /**
     * @return list<SiteMapProviderInterface>
     */
    public function providers(): array
    {
        $found = [];

        foreach ((new ClassDiscovery())->findClassesWithAttribute(AsSiteMap::class) as $class) {
            $provider = $this->instantiate($class);
            if ($provider instanceof SiteMapProviderInterface) {
                $found[] = $provider;
            }
        }

        return $found;
    }

    private function instantiate(string $class): ?object
    {
        try {
            return ContainerFactory::get()->get($class);
        } catch (\Throwable) {
            // A provider with no dependencies need not be container-managed.
            try {
                return new $class();
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
