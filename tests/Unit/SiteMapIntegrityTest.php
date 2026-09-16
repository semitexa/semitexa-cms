<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\SiteMapIntegrity;
use Semitexa\Cms\Domain\Model\Place;
use Semitexa\Cms\Domain\Model\PlaceVerdict;

/**
 * Every way the console can offer a place and then fail to open it.
 *
 * Written from a real report: a museum's whole structure branch was missing
 * after a move, and a page would not open. Both were found by the person whose
 * site it is, because the move had reported a row count and a row count cannot
 * tell nine places from forty-one rows.
 */
final class SiteMapIntegrityTest extends TestCase
{
    private SiteMapIntegrity $integrity;

    protected function setUp(): void
    {
        $this->integrity = new SiteMapIntegrity();
    }

    /** @param list<Place> $places @return array<string, string> ref => verdict */
    private function verdicts(array $places, array $editors = ['regmus:page'], array $records = [], array $sources = []): array
    {
        $checks = $this->integrity->check(
            $places,
            static fn (string $editor): bool => in_array($editor, $editors, true),
            static fn (string $editor, string $ref): bool => in_array($ref, $records, true),
            static fn (string $source): bool => in_array($source, $sources, true),
        );

        $out = [];
        foreach ($checks as $check) {
            $out[$check->ref] = $check->verdict->value;
        }

        return $out;
    }

    #[Test]
    public function aPlaceWhoseRecordIsGoneIsNamed(): void
    {
        // THE MUSEUM'S SYMPTOM. The map still offers the page; the row behind
        // it is not there; clicking it opens nothing.
        $places = [
            Place::site('regmus', 'Museum'),
            Place::page('regmus:page:7', 'Video', 'regmus', 'regmus:page'),
        ];

        self::assertSame(
            ['regmus' => 'reachable', 'regmus:page:7' => 'record_missing'],
            $this->verdicts($places, records: []),
        );
    }

    #[Test]
    public function aPlaceWhoseRecordIsThereOpens(): void
    {
        $places = [
            Place::site('regmus', 'Museum'),
            Place::page('regmus:page:7', 'Video', 'regmus', 'regmus:page'),
        ];

        self::assertSame('reachable', $this->verdicts($places, records: ['regmus:page:7'])['regmus:page:7']);
    }

    #[Test]
    public function anEditorNoModuleRegistersIsNamedBeforeTheRecordIsAskedFor(): void
    {
        // Order matters: asking a missing editor to load a ref would report
        // the record missing, which sends someone looking in the database for
        // a row that is fine.
        $places = [
            Place::site('regmus', 'Museum'),
            Place::page('regmus:page:7', 'Video', 'regmus', 'regmus:gone'),
        ];

        self::assertSame('editor_missing', $this->verdicts($places, records: ['regmus:page:7'])['regmus:page:7']);
    }

    #[Test]
    public function aPlaceHangingFromNothingIsNamed(): void
    {
        // What a vanished BRANCH looks like from below: the parent is gone, so
        // the children are on the map and nothing links to them.
        $places = [
            Place::site('regmus', 'Museum'),
            Place::page('regmus:page:12', 'Halls', 'regmus:page:structure', 'regmus:page'),
        ];

        self::assertSame('orphan_parent', $this->verdicts($places, records: ['regmus:page:12'])['regmus:page:12']);
    }

    #[Test]
    public function aCollectionWithNothingListingItsRowsIsNamed(): void
    {
        $places = [
            Place::site('regmus', 'Museum'),
            Place::collection('regmus:events', 'Events', 'regmus:events', 'regmus'),
        ];

        self::assertSame('source_missing', $this->verdicts($places)['regmus:events']);
    }

    #[Test]
    public function aCollectionWhoseSourceListsRowsOpens(): void
    {
        $places = [
            Place::site('regmus', 'Museum'),
            Place::collection('regmus:events', 'Events', 'regmus:events', 'regmus'),
        ];

        self::assertSame('reachable', $this->verdicts($places, sources: ['regmus:events'])['regmus:events']);
    }

    #[Test]
    public function twoPlacesClaimingOneRefAreNamed(): void
    {
        $places = [
            Place::site('regmus', 'Museum'),
            Place::page('regmus:page:7', 'Video', 'regmus', 'regmus:page'),
            Place::page('regmus:page:7', 'Video (copy)', 'regmus', 'regmus:page'),
        ];

        // The second one loses: by ref it is unreachable, whatever it is called.
        self::assertSame('duplicate_ref', $this->verdicts($places, records: ['regmus:page:7'])['regmus:page:7']);
    }

    #[Test]
    public function theSiteItselfIsAlwaysReachable(): void
    {
        // It hangs from nothing and opens nothing; judging it by the rules for
        // a page would report the root of every map as broken.
        self::assertSame(['regmus' => 'reachable'], $this->verdicts([Place::site('regmus', 'Museum')]));
    }

    #[Test]
    public function aCleanMapReportsNoProblems(): void
    {
        $places = [
            Place::site('regmus', 'Museum'),
            Place::page('regmus:page:1', 'About', 'regmus', 'regmus:page'),
            Place::collection('regmus:events', 'Events', 'regmus:events', 'regmus'),
        ];

        $checks = $this->integrity->check(
            $places,
            static fn (string $e): bool => $e === 'regmus:page',
            static fn (string $e, string $ref): bool => $ref === 'regmus:page:1',
            static fn (string $s): bool => $s === 'regmus:events',
        );

        self::assertSame([], $this->integrity->problems($checks));
    }

    #[Test]
    public function everyFindingExplainsItselfInOneLine(): void
    {
        // The report is read by the person whose site it is, not only by the
        // agent that ran it.
        $places = [
            Place::site('regmus', 'Museum'),
            Place::page('regmus:page:7', 'Video', 'regmus', 'regmus:page'),
        ];

        $checks = $this->integrity->check(
            $places,
            static fn (string $e): bool => true,
            static fn (string $e, string $ref): bool => false,
            static fn (string $s): bool => true,
        );

        $problem = $this->integrity->problems($checks)[0];
        self::assertStringContainsString('Video', $problem->explain());
        self::assertStringContainsString('cannot open it', $problem->explain());
    }
}
