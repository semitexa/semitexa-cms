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
}
