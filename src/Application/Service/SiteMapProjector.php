<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Attribute\AsSiteMap;
use Semitexa\Cms\Domain\Contract\SiteMapProviderInterface;
use Semitexa\Cms\Domain\Model\Place;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Psr\Container\ContainerInterface;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Node;
use Semitexa\Weave\Domain\Model\Relation;

/**
 * Writes a site's map into the graph the OS already keeps.
 *
 * Not a second graph: the site belongs in the one the person lives in, hanging
 * where it actually hangs — person → the work they do → the site of that work →
 * its pages. A module contributes its nodes to that world rather than owning a
 * private copy of it.
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
     * Providers are found by attribute and named at runtime, so there is no
     * property to inject them into — this is the dynamic-dispatch seam the
     * container exists for.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /**
     * The graph is per-tenant knowledge, so a map built outside its tenant's
     * context lands in a world nobody looks at: the console runs as the tenant
     * and would show an empty map while the rows sit under 'default'.
     */
    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

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
                // Every place gets an edge: to its declared parent, or to the
                // site itself. Counting only explicit parents would report zero
                // for a flat map and read as "nothing would be linked".
                'edges' => count($places),
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

        $this->anchor($site->id, $siteRef, $provider->workTitle());

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
     * Hang the site where it belongs in the person's world: under the work it
     * is the site OF, which in turn hangs off them.
     *
     * The work node is usually already there — the assistant learns "I work at
     * the museum" long before anyone builds a map — so we look for it before
     * minting one. Matching is by shared word stems rather than by title,
     * because the conversational node arrives inflected ("Чернівецького
     * обласного музею") and would never equal the site's own nominative title.
     */
    private function anchor(string $siteId, string $siteRef, ?string $workTitle): void
    {
        $workTitle = trim((string) $workTitle);
        if ($workTitle === '') {
            return;
        }

        $work = $this->existingWork($workTitle)
            ?? $this->graph->upsertNodeByRef(NodeKind::Org, $siteRef . ':org', $workTitle, [], self::SOURCE);

        $this->graph->addEdge($work->id, $siteId, Relation::PART_OF, 100, self::SOURCE);

        // Without this the work floats: the person's own node is what makes the
        // rest of the world reachable from them.
        $self = $this->self();
        if ($self !== null) {
            $this->graph->addEdge($self->id, $work->id, Relation::WORKS_ON, 100, self::SOURCE);
        }
    }

    private function existingWork(string $workTitle): ?Node
    {
        $wanted = self::stems($workTitle);
        if ($wanted === []) {
            return null;
        }

        foreach ($this->graph->graph(500, [NodeKind::Org, NodeKind::Project])['nodes'] as $node) {
            $shared = array_intersect($wanted, self::stems($node->title));

            // Two shared stems is enough to recognise the same organisation and
            // strict enough that "музей" alone does not fuse two of them.
            if (count($shared) >= 2) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Content words cut to their first letters, so Ukrainian and Polish endings
     * stop mattering: "обласного" and "обласний" both reduce to "облас".
     *
     * @return list<string>
     */
    private static function stems(string $title): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($title))) ?: [];
        $stems = [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 5) {
                $stems[] = mb_substr($word, 0, 5);
            }
        }

        return array_values(array_unique($stems));
    }

    /** The person the world belongs to, if the OS has established one. */
    private function self(): ?Node
    {
        foreach ($this->graph->nodesByKind(NodeKind::Person) as $node) {
            if (($node->properties['is_self'] ?? false) === true) {
                return $node;
            }
        }

        return null;
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
        $current = $this->currentTenantId();
        $found = [];

        foreach ((new ClassDiscovery())->findClassesWithAttribute(AsSiteMap::class) as $class) {
            $declared = $this->declaredTenant($class);

            // A map declared for another tenant is skipped rather than written
            // into whichever context happens to be ambient. Running the build
            // without a tenant used to file a museum's whole map under
            // 'default', where the console — which always runs as the tenant —
            // could not see a single node of it.
            if ($declared !== '' && $declared !== $current) {
                continue;
            }

            $provider = $this->instantiate($class);
            if ($provider instanceof SiteMapProviderInterface) {
                $found[] = $provider;
            }
        }

        return $found;
    }

    /** Maps declared for a tenant other than the ambient one, and their tenants. */
    public function skippedTenants(): array
    {
        $current = $this->currentTenantId();
        $skipped = [];

        foreach ((new ClassDiscovery())->findClassesWithAttribute(AsSiteMap::class) as $class) {
            $declared = $this->declaredTenant($class);
            if ($declared !== '' && $declared !== $current) {
                $skipped[$declared] = $declared;
            }
        }

        return array_values($skipped);
    }

    public function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
    }

    private function declaredTenant(string $class): string
    {
        try {
            $attributes = (new \ReflectionClass($class))->getAttributes(AsSiteMap::class);
        } catch (\Throwable) {
            return '';
        }

        return $attributes === [] ? '' : trim($attributes[0]->newInstance()->tenant);
    }

    private function instantiate(string $class): ?object
    {
        if (isset($this->container)) {
            try {
                return $this->container->get($class);
            } catch (\Throwable) {
                // Fall through: not every provider needs to be a service.
            }
        }

        try {
            return new $class();
        } catch (\Throwable) {
            return null;
        }
    }
}
