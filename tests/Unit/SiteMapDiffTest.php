<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\SiteMapDiff;
use Semitexa\Cms\Domain\Model\MapChangeKind;
use Semitexa\Cms\Domain\Model\MapChangeRecord;

/**
 * The reconciliation a move owes the person whose site it is.
 *
 * Each case here is something that actually happened to a museum's site and was
 * reported by its owner rather than by anything that ran: a whole branch gone,
 * a page that stopped opening. A move said success, and success meant a row
 * count.
 */
final class SiteMapDiffTest extends TestCase
{
    private SiteMapDiff $diff;

    protected function setUp(): void
    {
        $this->diff = new SiteMapDiff();
    }

    /** @param list<array<string, mixed>> $places */
    private function site(array $places, string $ref = 'regmus'): array
    {
        return [$ref => ['title' => 'Museum', 'places' => $places]];
    }

    /** @param array<string, mixed> $overrides */
    private function place(string $ref, array $overrides = []): array
    {
        return array_merge([
            'ref' => $ref,
            'title' => ucfirst(substr($ref, strrpos($ref, ':') + 1)),
            'parent' => 'regmus',
            'kind' => 'page',
            'order' => 0,
            'opens' => 'regmus:page',
            'verdict' => 'reachable',
        ], $overrides);
    }

    /** @return list<MapChangeRecord> */
    private function changesFor(array $before, array $after, string $siteRef = 'regmus'): array
    {
        return $this->diff->between($before, $after)[$siteRef] ?? [];
    }

    #[Test]
    public function aPlaceThatLeftIsNamed(): void
    {
        $before = $this->site([$this->place('regmus:page:1'), $this->place('regmus:page:structure')]);
        $after = $this->site([$this->place('regmus:page:1')]);

        $changes = $this->changesFor($before, $after);

        self::assertCount(1, $changes);
        self::assertSame(MapChangeKind::Gone, $changes[0]->kind);
        self::assertSame('regmus:page:structure', $changes[0]->ref);
        self::assertStringContainsString('gone', $changes[0]->message);
    }

    #[Test]
    public function aWholeMapThatLeftIsTheLoudestFinding(): void
    {
        $changes = $this->changesFor($this->site([$this->place('regmus:page:1')]), []);

        self::assertSame(MapChangeKind::SiteGone, $changes[0]->kind);
        self::assertStringContainsString('the whole map', $changes[0]->message);
    }

    #[Test]
    public function aPlaceThatStoppedOpeningIsNamedEvenThoughItIsStillThere(): void
    {
        // The subtle one, and the one a row count is blindest to: the place is
        // on the map before and after, and the record behind it went away.
        $before = $this->site([$this->place('regmus:page:7', ['title' => 'Video'])]);
        $after = $this->site([$this->place('regmus:page:7', ['title' => 'Video', 'verdict' => 'record_missing'])]);

        $changes = $this->changesFor($before, $after);

        self::assertSame(MapChangeKind::VerdictChanged, $changes[0]->kind);
        self::assertStringContainsString('reachable → record_missing', $changes[0]->message);
    }

    #[Test]
    public function aRenameIsNotALossAndSaysSo(): void
    {
        $before = $this->site([$this->place('regmus:page:1', ['title' => 'About'])]);
        $after = $this->site([$this->place('regmus:page:1', ['title' => 'About the museum'])]);

        $changes = $this->changesFor($before, $after);

        self::assertSame(MapChangeKind::Renamed, $changes[0]->kind);
        self::assertStringContainsString('About → About the museum', $changes[0]->message);
    }

    #[Test]
    public function aMoveNamesBothEnds(): void
    {
        $before = $this->site([$this->place('regmus:page:9', ['parent' => 'regmus'])]);
        $after = $this->site([$this->place('regmus:page:9', ['parent' => 'regmus:page:structure'])]);

        $changes = $this->changesFor($before, $after);

        self::assertSame(MapChangeKind::Moved, $changes[0]->kind);
        self::assertStringContainsString('now hangs from regmus:page:structure', $changes[0]->message);
        self::assertStringContainsString('was regmus', $changes[0]->message);
    }

    #[Test]
    public function aNewPlaceIsReportedToo(): void
    {
        $changes = $this->changesFor($this->site([]), $this->site([$this->place('regmus:page:2')]));

        self::assertSame(MapChangeKind::New, $changes[0]->kind);
    }

    #[Test]
    public function anUnchangedMapReportsNothing(): void
    {
        // The property that makes the report readable: a move that changed
        // nothing has to produce silence, or nobody reads the one that did.
        $map = $this->site([$this->place('regmus:page:1'), $this->place('regmus:page:2')]);

        self::assertSame([], $this->changesFor($map, $map));
    }

    #[Test]
    public function aSnapshotWithRubbishInItDoesNotBreakTheComparison(): void
    {
        // Snapshots are files, and a file can be half-written, hand-edited or
        // from an older shape. A diff that fatals on one is a diff nobody runs
        // at the moment they need it most.
        $before = ['regmus' => ['places' => ['nonsense', ['no-ref' => true], $this->place('regmus:page:1')]]];
        $after = $this->site([]);

        $changes = $this->changesFor($before, $after);

        self::assertCount(1, $changes);
        self::assertSame('regmus:page:1', $changes[0]->ref);
    }

    #[Test]
    public function a_snapshot_site_that_is_not_a_map_does_not_crash_the_report(): void
    {
        // The snapshot is a file somebody can edit, and the reader only checks
        // that it parses as JSON. A site that came back as a string used to be
        // indexed with ['places'] and PHP raised a TypeError before any report
        // was written — the comparison died instead of reporting.
        $changes = (new SiteMapDiff())->between(
            ['regmus' => 'not a map at all'],
            ['regmus' => ['places' => [['ref' => 'page:about', 'title' => 'About']]]],
        );

        self::assertArrayHasKey('regmus', $changes);
    }

    /** @param array<string, string>|null $head null = a snapshot taken before head values were recorded */
    private function siteWithHead(?array $head): array
    {
        $site = ['title' => 'Museum', 'places' => [$this->place('regmus:about')]];
        if ($head !== null) {
            $site['head'] = $head;
        }

        return ['regmus' => $site];
    }

    /**
     * The incident: after a move the analytics id was simply not there, every
     * page opened, and the owner learned it from empty reports a week later.
     */
    #[Test]
    public function an_analytics_id_that_did_not_come_across_is_named(): void
    {
        $changes = $this->diff->between(
            $this->siteWithHead(['ga4_measurement_id' => 'G-ABC123XYZ']),
            $this->siteWithHead([]),
        )['regmus'];

        self::assertCount(1, $changes);
        self::assertSame(MapChangeKind::HeadValueGone, $changes[0]->kind);
        self::assertSame('ga4_measurement_id', $changes[0]->ref);
        self::assertStringContainsString('G-ABC123XYZ', $changes[0]->message);
    }

    #[Test]
    public function a_changed_head_value_is_reported(): void
    {
        $changes = $this->diff->between(
            $this->siteWithHead(['google_site_verification' => 'old-token-0123456789']),
            $this->siteWithHead(['google_site_verification' => 'new-token-0123456789']),
        )['regmus'];

        self::assertSame([MapChangeKind::HeadValueChanged], array_map(static fn ($c) => $c->kind, $changes));
    }

    /** A snapshot from before head values were recorded says nothing about them. */
    #[Test]
    public function an_older_snapshot_without_head_values_raises_nothing(): void
    {
        self::assertSame([], $this->diff->between($this->siteWithHead(null), $this->siteWithHead([]))['regmus']);
    }

    /** The comparison is against a snapshot that went through JSON — an empty head included. */
    #[Test]
    public function head_values_survive_the_snapshot_round_trip(): void
    {
        $before = json_decode((string) json_encode($this->siteWithHead([])), true);
        self::assertSame([], $this->diff->between($before, $this->siteWithHead([]))['regmus']);

        $before = json_decode((string) json_encode($this->siteWithHead(['plausible_domain' => 'example.com'])), true);
        self::assertSame([], $this->diff->between($before, $this->siteWithHead(['plausible_domain' => 'example.com']))['regmus']);
    }
}
