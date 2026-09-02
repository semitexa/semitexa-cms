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
    private function provider(array $places): SiteMapProviderInterface
    {
        return new class ($places) implements SiteMapProviderInterface {
            /** @param list<Place> $places */
            public function __construct(private array $places) {}

            public function siteRef(): string
            {
                return 'regmus';
            }

            public function siteTitle(): string
            {
                return 'Museum';
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

        self::assertSame(['site' => 'regmus', 'places' => 2, 'edges' => 2, 'stale' => []], $report);
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
        self::assertSame(86, $collections[0]->properties['count'] ?? null);
    }

    #[Test]
    public function a_place_renamed_by_a_person_survives_the_next_rebuild_as_the_same_node(): void
    {
        $projector = $this->projector();
        $projector->project($this->provider([Place::page('regmus:page:1', 'Home Page', editor: 'regmus:page')]));

        $node = $this->store->nodeByRef('regmus:page:1');
        self::assertNotNull($node);
        $this->store->updateNode($node->id, 'Головна');

        // The provider still reports the database title; the operator's rename
        // is the better one, but what must NOT happen is a second node.
        $projector->project($this->provider([Place::page('regmus:page:1', 'Home Page', editor: 'regmus:page')]));

        self::assertCount(1, $this->store->nodesByKind(NodeKind::Page));
        self::assertSame($node->id, $this->store->nodeByRef('regmus:page:1')?->id);
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
