<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\SiteMapProjector;
use Semitexa\Cms\Domain\Contract\SiteMapProviderInterface;
use Semitexa\Cms\Domain\Model\Place;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Weave\Application\Service\GraphStore;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Node;

/**
 * The projection runs against a real graph store: what matters is that a
 * rebuild is safe, and that is a property of the store's identity rules, not of
 * a double's.
 */
final class SiteMapProjectorTest extends TestCase
{
    private OrmManager $orm;
    private GraphStore $store;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $db = $this->orm->getAdapter();
        $db->execute(
            'CREATE TABLE weave_node (
                id TEXT PRIMARY KEY, tenant_id TEXT, kind TEXT NOT NULL, title TEXT NOT NULL, title_key TEXT NOT NULL,
                ext_ref TEXT, properties_json TEXT NOT NULL, source TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        );
        $db->execute('CREATE UNIQUE INDEX uniq_weave_node_kind_title ON weave_node (tenant_id, kind, title_key)');
        $db->execute('CREATE UNIQUE INDEX uniq_weave_node_ext_ref ON weave_node (tenant_id, ext_ref)');
        $db->execute(
            'CREATE TABLE weave_edge (
                id TEXT PRIMARY KEY, tenant_id TEXT, from_id TEXT NOT NULL, to_id TEXT NOT NULL, relation TEXT NOT NULL,
                weight INTEGER NOT NULL, source TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        );
        $db->execute('CREATE UNIQUE INDEX uniq_weave_edge_triple ON weave_edge (from_id, to_id, relation)');

        $this->store = new GraphStore();
        (new \ReflectionProperty(GraphStore::class, 'orm'))->setValue($this->store, $this->orm);
    }

    private function projector(): SiteMapProjector
    {
        $projector = new SiteMapProjector();
        (new \ReflectionProperty(SiteMapProjector::class, 'graph'))->setValue($projector, $this->store);

        return $projector;
    }

    /** @param list<Place> $places */
    private function provider(array $places, ?string $workTitle = null): SiteMapProviderInterface
    {
        return new class ($places, $workTitle) implements SiteMapProviderInterface {
            /** @param list<Place> $places */
            public function __construct(private array $places, private ?string $workTitle) {}

            public function siteRef(): string
            {
                return 'regmus';
            }

            public function siteTitle(): string
            {
                return 'Museum';
            }

            public function workTitle(): ?string
            {
                return $this->workTitle;
            }

            public function watches(): array
            {
                return [];
            }

            public function places(): iterable
            {
                return $this->places;
            }
        };
    }

    #[Test]
    public function a_map_becomes_a_site_its_places_and_an_edge_each(): void
    {
        $report = $this->projector()->project($this->provider([
            Place::page('regmus:page:3', 'Контакти', editor: 'regmus:page'),
            Place::collection('regmus:events', 'Події', source: 'regmus:pages?type=event'),
        ]));

        self::assertSame(['site' => 'regmus', 'places' => 2, 'edges' => 2, 'stale' => [], 'removed' => []], $report);
        self::assertCount(1, $this->store->nodesByKind(NodeKind::Site));
        self::assertCount(1, $this->store->nodesByKind(NodeKind::Page));
        self::assertCount(1, $this->store->nodesByKind(NodeKind::Collection));
    }

    #[Test]
    public function rebuilding_after_the_content_changed_updates_rather_than_duplicates(): void
    {
        // The rebuild is the common case: new events arrive, someone re-runs it.
        // Identity by ref is what stops that from doubling the map every time.
        $projector = $this->projector();
        $projector->project($this->provider([
            Place::collection('regmus:events', 'Події', source: 'regmus:pages?type=event', properties: ['count' => 85]),
        ]));
        $projector->project($this->provider([
            Place::collection('regmus:events', 'Події', source: 'regmus:pages?type=event', properties: ['count' => 86]),
        ]));

        $collections = $this->store->nodesByKind(NodeKind::Collection);
        self::assertCount(1, $collections);
        self::assertSame(86, $collections[0]->getProperties()['count'] ?? null);
    }

    #[Test]
    public function a_place_renamed_by_a_person_survives_the_next_rebuild_as_the_same_node(): void
    {
        $projector = $this->projector();
        $projector->project($this->provider([Place::page('regmus:page:1', 'Home Page', editor: 'regmus:page')]));

        $node = $this->store->nodeByRef('regmus:page:1');
        self::assertNotNull($node);
        $this->store->updateNode($node->getId(), 'Головна');

        // The provider still reports the database title; the operator's rename
        // is the better one, but what must NOT happen is a second node.
        $projector->project($this->provider([Place::page('regmus:page:1', 'Home Page', editor: 'regmus:page')]));

        self::assertCount(1, $this->store->nodesByKind(NodeKind::Page));
        self::assertSame($node->getId(), $this->store->nodeByRef('regmus:page:1')?->getId());
    }

    #[Test]
    public function a_rename_survives_the_rebuild_that_follows_any_content_change(): void
    {
        // The sequence that silently reverted: a person renames a place, then
        // anyone saves any watched content and SiteMapRefreshListener
        // re-projects. Before the guard the provider title won and the rename
        // was gone, with nothing to show it had ever been made.
        $projector = $this->projector();
        $projector->project($this->provider([Place::page('regmus:page:1', 'Home Page', editor: 'regmus:page')]));

        $node = $this->store->nodeByRef('regmus:page:1');
        self::assertNotNull($node);
        $this->store->updateNode($node->getId(), 'Головна');

        $projector->project($this->provider([Place::page('regmus:page:1', 'Home Page', editor: 'regmus:page')]));

        self::assertSame('Головна', $this->store->nodeByRef('regmus:page:1')?->getTitle());
    }

    #[Test]
    public function an_order_someone_chose_survives_the_next_rebuild(): void
    {
        $projector = $this->projector();
        $projector->project($this->provider([Place::page('regmus:page:1', 'Home Page', editor: 'regmus:page', order: 10)]));

        $node = $this->store->nodeByRef('regmus:page:1');
        self::assertNotNull($node);
        $this->store->updateNode($node->getId(), null, ['order' => 3]);

        $projector->project($this->provider([Place::page('regmus:page:1', 'Home Page', editor: 'regmus:page', order: 10)]));

        self::assertSame(3, $this->store->nodeByRef('regmus:page:1')?->getProperties()['order'] ?? null);
    }

    #[Test]
    public function an_untouched_place_still_follows_the_module(): void
    {
        // The guard must not freeze the map: a title nobody has touched is the
        // module's to correct, which is the whole point of re-projecting.
        $projector = $this->projector();
        $projector->project($this->provider([Place::page('regmus:page:1', 'Home Page', editor: 'regmus:page', order: 1)]));

        $projector->project($this->provider([Place::page('regmus:page:1', 'Home — renamed in the CMS', editor: 'regmus:page', order: 7)]));

        $node = $this->store->nodeByRef('regmus:page:1');
        self::assertSame('Home — renamed in the CMS', $node?->getTitle());
        self::assertSame(7, $node?->getProperties()['order'] ?? null);
    }

    #[Test]
    public function a_node_projected_before_the_guard_existed_is_not_mistaken_for_an_edit(): void
    {
        // Upgrade path: an existing node carries no baseline, so the first pass
        // after the upgrade must behave exactly as it did before and seed one.
        // Reading this as "someone changed it" would freeze every map in place.
        $this->store->upsertNodeByRef(NodeKind::Page, 'regmus:page:1', 'Stale Title', ['order' => 0], SiteMapProjector::SOURCE);

        $this->projector()->project($this->provider([Place::page('regmus:page:1', 'Home Page', editor: 'regmus:page', order: 4)]));

        $node = $this->store->nodeByRef('regmus:page:1');
        self::assertSame('Home Page', $node?->getTitle());
        self::assertSame(4, $node?->getProperties()['order'] ?? null);
        self::assertSame(
            ['title' => 'Home Page', 'order' => 4],
            $node?->getProperties()[SiteMapProjector::PROJECTED] ?? null,
        );
    }

    #[Test]
    public function a_place_that_left_the_map_is_reported_and_not_deleted(): void
    {
        // Silently removing part of someone's map is the one behaviour that
        // would stop them trusting a rebuild.
        $projector = $this->projector();
        $projector->project($this->provider([
            Place::page('regmus:page:3', 'Контакти', editor: 'regmus:page'),
            Place::page('regmus:page:9', 'Стара сторінка', editor: 'regmus:page'),
        ]));

        $report = $projector->project($this->provider([
            Place::page('regmus:page:3', 'Контакти', editor: 'regmus:page'),
        ]));

        self::assertSame(['regmus:page:9'], $report['stale']);
        self::assertNotNull($this->store->nodeByRef('regmus:page:9'));
    }

    #[Test]
    public function a_place_a_person_removed_is_not_brought_back_by_the_next_rebuild(): void
    {
        // The sequence that undid itself: a person removes a place, anyone saves
        // watched content, SiteMapRefreshListener re-projects — and the place
        // came back as a different node.
        $projector = $this->projector();
        $map = $this->provider([
            Place::page('regmus:page:3', 'Контакти', editor: 'regmus:page'),
            Place::page('regmus:page:9', 'Архів', editor: 'regmus:page'),
        ]);
        $projector->project($map);

        $node = $this->store->nodeByRef('regmus:page:9');
        self::assertNotNull($node);
        $this->store->removeNode($node->getId());

        $report = $projector->project($map);

        self::assertNull($this->store->nodeByRef('regmus:page:9'));
        self::assertSame(['regmus:page:9'], $report['removed']);
        self::assertSame(1, $report['places']);
        self::assertSame([], $report['stale']);
    }

    #[Test]
    public function a_removal_is_still_remembered_on_every_rebuild_after_it(): void
    {
        // The pass that notices the removal rewrites the baseline; if it dropped
        // the ref from it, the pass after would see a brand-new place.
        $projector = $this->projector();
        $map = $this->provider([Place::page('regmus:page:9', 'Архів', editor: 'regmus:page')]);
        $projector->project($map);

        $this->store->removeNode((string) $this->store->nodeByRef('regmus:page:9')?->getId());
        $projector->project($map);
        $projector->project($map);

        self::assertNull($this->store->nodeByRef('regmus:page:9'));
    }

    #[Test]
    public function a_place_new_to_the_map_is_still_created_after_a_removal(): void
    {
        // The guard must recognise a removal, not freeze the map's membership.
        $projector = $this->projector();
        $projector->project($this->provider([Place::page('regmus:page:9', 'Архів', editor: 'regmus:page')]));
        $this->store->removeNode((string) $this->store->nodeByRef('regmus:page:9')?->getId());

        $report = $projector->project($this->provider([
            Place::page('regmus:page:9', 'Архів', editor: 'regmus:page'),
            Place::page('regmus:page:12', 'Новини', editor: 'regmus:page'),
        ]));

        self::assertNotNull($this->store->nodeByRef('regmus:page:12'));
        self::assertSame(['regmus:page:9'], $report['removed']);
    }

    #[Test]
    public function a_site_projected_before_the_baseline_existed_recreates_what_is_missing(): void
    {
        // Upgrade path: no list on the site node means no way to tell a removal
        // from a place never projected, so this pass behaves as before and seeds.
        $this->store->upsertNodeByRef(NodeKind::Site, 'regmus', 'Museum', ['origin' => 'site'], SiteMapProjector::SOURCE);

        $report = $this->projector()->project($this->provider([Place::page('regmus:page:9', 'Архів', editor: 'regmus:page')]));

        self::assertNotNull($this->store->nodeByRef('regmus:page:9'));
        self::assertSame([], $report['removed']);
        self::assertSame(['regmus:page:9'], $this->store->nodeByRef('regmus')?->getProperties()[SiteMapProjector::PLACES] ?? null);
    }

    #[Test]
    public function the_children_of_a_removed_place_hang_off_the_site(): void
    {
        $projector = $this->projector();
        $map = $this->provider([
            Place::collection('regmus:events', 'Події', source: 'regmus:pages?type=event'),
            Place::page('regmus:page:40', 'Виставка', editor: 'regmus:page', parentRef: 'regmus:events'),
        ]);
        $projector->project($map);
        $this->store->removeNode((string) $this->store->nodeByRef('regmus:events')?->getId());

        $report = $projector->project($map);

        $site = $this->store->nodeByRef('regmus');
        $child = $this->store->nodeByRef('regmus:page:40');
        self::assertNotNull($site);
        self::assertNotNull($child);
        self::assertSame(1, $report['edges']);
        $neighbours = array_map(static fn ($n): string => $n->getId(), $this->store->neighborhood($child->getId())['neighbors']);
        self::assertSame([$site->getId()], $neighbours);
    }

    #[Test]
    public function a_pass_that_fails_halfway_does_not_mistake_what_it_never_created_for_a_removal(): void
    {
        // The store gives out on the second place of the very first pass.
        $failing = new class () extends GraphStore {
            public int $placesLeft = 1;

            public function upsertNodeByRef(NodeKind $kind, string $ref, string $title, array $properties = [], string $source = ''): Node
            {
                if ($kind === NodeKind::Page && $this->placesLeft-- <= 0) {
                    throw new \RuntimeException('store went away');
                }

                return parent::upsertNodeByRef($kind, $ref, $title, $properties, $source);
            }
        };
        (new \ReflectionProperty(GraphStore::class, 'orm'))->setValue($failing, $this->orm);
        $broken = new SiteMapProjector();
        (new \ReflectionProperty(SiteMapProjector::class, 'graph'))->setValue($broken, $failing);

        $map = $this->provider([
            Place::page('regmus:page:3', 'Контакти', editor: 'regmus:page'),
            Place::page('regmus:page:9', 'Архів', editor: 'regmus:page'),
        ]);
        try {
            $broken->project($map);
            self::fail('the store was meant to fail');
        } catch (\RuntimeException) {
        }

        $report = $this->projector()->project($map);

        self::assertSame([], $report['removed']);
        self::assertNotNull($this->store->nodeByRef('regmus:page:9'));
    }

    #[Test]
    public function a_dry_run_reports_a_removal_without_writing(): void
    {
        $projector = $this->projector();
        $map = $this->provider([Place::page('regmus:page:9', 'Архів', editor: 'regmus:page')]);
        $projector->project($map);
        $this->store->removeNode((string) $this->store->nodeByRef('regmus:page:9')?->getId());

        $report = $projector->project($map, dryRun: true);

        self::assertSame(['regmus:page:9'], $report['removed']);
        self::assertSame(0, $report['places']);
    }

    #[Test]
    public function the_site_hangs_off_the_work_the_person_already_told_the_assistant_about(): void
    {
        // The conversational node arrives inflected — "Чернівецького обласного
        // музею" — and would never equal the site's own nominative title, so a
        // title match would mint a second museum next to the first.
        $existing = $this->store->upsertNode(
            NodeKind::Org,
            'Чернівецького обласного музею',
            [],
            'os:weaver',
        );

        $this->projector()->project(
            $this->provider([Place::page('regmus:page:3', 'Контакти')], 'Чернівецький обласний краєзнавчий музей'),
        );

        self::assertCount(1, $this->store->nodesByKind(NodeKind::Org), 'The museum must not be duplicated.');

        $neighbourhood = $this->store->neighborhood($existing->getId());
        $titles = array_map(static fn ($n): string => $n->getTitle(), $neighbourhood['neighbors'] ?? []);
        self::assertContains('Museum', $titles, 'The site should hang off the work.');
    }

    #[Test]
    public function an_unrelated_organisation_is_not_mistaken_for_the_work(): void
    {
        $this->store->upsertNode(NodeKind::Org, 'Львівська політехніка', [], 'os:weaver');

        $this->projector()->project(
            $this->provider([Place::page('regmus:page:3', 'Контакти')], 'Чернівецький обласний краєзнавчий музей'),
        );

        // Two organisations now: the unrelated one, and the museum this map
        // created because nothing matched it.
        self::assertCount(2, $this->store->nodesByKind(NodeKind::Org));
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $report = $this->projector()->project(
            $this->provider([Place::page('regmus:page:3', 'Контакти')]),
            dryRun: true,
        );

        self::assertSame(1, $report['places']);
        self::assertSame([], $this->store->nodesByKind(NodeKind::Page));
        self::assertSame([], $this->store->nodesByKind(NodeKind::Site));
    }
}
