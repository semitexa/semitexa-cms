<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentListSkill;
use Semitexa\Cms\Domain\Contract\SiteMapProviderInterface;
use Semitexa\Cms\Domain\Model\ContentRow;
use Semitexa\Cms\Domain\Model\ContentRows;
use Semitexa\Cms\Domain\Model\Place;

/**
 * What of the site a person may edit, answered in a conversation.
 *
 * Every line has to carry a ref: a ref is what the editor skill and each
 * tenant's own `*:page:edit` take, so a listing without one is a listing the
 * planner cannot act on.
 *
 * The two services that FIND maps and collections are final, so these drive the
 * rendering directly over the interfaces they return — which is where every
 * decision under test lives.
 */
final class ContentListSkillTest extends TestCase
{
    private function provider(string $ref, string $title, array $places): SiteMapProviderInterface
    {
        return new class ($ref, $title, $places) implements SiteMapProviderInterface {
            public function __construct(private string $ref, private string $title, private array $places) {}

            public function siteRef(): string { return $this->ref; }
            public function siteTitle(): string { return $this->title; }
            public function workTitle(): ?string { return null; }
            public function watches(): array { return []; }
            public function places(): iterable { return $this->places; }
        };
    }

    private function render(string $method, mixed ...$args): string
    {
        return (string) (new \ReflectionMethod(ContentListSkill::class, $method))->invoke(null, ...$args);
    }

    private function museum(): SiteMapProviderInterface
    {
        return $this->provider('regmus', 'Regional Museum', [
            Place::site('regmus', 'Regional Museum'),
            Place::page('regmus:page:7', 'About the museum', null, 'regmus:page'),
            Place::page('regmus:page:9', 'Visiting', null, 'regmus:page'),
            Place::collection('regmus:events', 'Events', 'regmus:pages?type=event'),
        ]);
    }

    #[Test]
    public function the_map_lists_pages_and_collections_with_their_refs(): void
    {
        $out = $this->render('renderMap', [$this->museum()], '');

        $this->assertStringContainsString('Regional Museum', $out);
        $this->assertStringContainsString('regmus:page:7  About the museum', $out);
        $this->assertStringContainsString('regmus:events  Events', $out);
        $this->assertStringContainsString('Pages', $out);
        $this->assertStringContainsString('Collections', $out);
    }

    #[Test]
    public function the_site_itself_is_a_heading_not_a_line_under_it(): void
    {
        $out = $this->render('renderMap', [$this->museum()], '');

        // 'regmus' appears as a heading via siteTitle, never as an editable place.
        $this->assertStringNotContainsString('    regmus  ', $out);
    }

    #[Test]
    public function search_narrows_by_title_and_says_what_it_hid(): void
    {
        $out = $this->render('renderMap', [$this->museum()], 'visit');

        $this->assertStringContainsString('regmus:page:9  Visiting', $out);
        $this->assertStringNotContainsString('About the museum', $out);
        $this->assertStringContainsString('2 other place(s) not shown', $out);
    }

    #[Test]
    public function a_search_that_matches_nothing_says_so_rather_than_listing_nothing(): void
    {
        $out = $this->render('renderMap', [$this->museum()], 'zzz');

        $this->assertSame('Nothing on the map matches "zzz".', $out);
    }

    #[Test]
    public function an_install_with_no_map_is_told_how_to_get_one(): void
    {
        $out = $this->render('renderMap', [], '');

        $this->assertStringContainsString('no content map', $out);
        $this->assertStringContainsString('#[AsSiteMap]', $out);
    }

    #[Test]
    public function rows_carry_refs_meta_and_where_the_reader_is(): void
    {
        $rows = new ContentRows('Events', [
            new ContentRow('regmus:page:41', 'Night at the museum', ['2026-05-18', 'published']),
            new ContentRow('regmus:page:42', 'Spring lecture'),
        ], total: 41, page: 2, perPage: 20);

        $out = $this->render('renderRows', $rows, '');

        $this->assertStringContainsString('Events — page 2 of 3, 41 record(s) in all.', $out);
        $this->assertStringContainsString('regmus:page:41  Night at the museum  (2026-05-18, published)', $out);
        $this->assertStringContainsString('regmus:page:42  Spring lecture', $out);
        $this->assertStringContainsString('Ask for page 3 to see more.', $out);
    }

    #[Test]
    public function the_last_page_does_not_offer_a_next_one(): void
    {
        $rows = new ContentRows('Events', [new ContentRow('regmus:page:41', 'Night')], total: 1, page: 1, perPage: 20);

        $this->assertStringNotContainsString('Ask for page', $this->render('renderRows', $rows, ''));
    }

    #[Test]
    public function filtering_a_page_admits_it_only_filtered_that_page(): void
    {
        // The source's query is the module's own vocabulary; inventing a `search`
        // filter for it would be putting words in its mouth. So the filter runs
        // over the fetched page, and the answer has to say exactly that instead
        // of implying the whole collection was searched.
        $rows = new ContentRows('Events', [
            new ContentRow('regmus:page:41', 'Night at the museum'),
            new ContentRow('regmus:page:42', 'Spring lecture'),
        ], total: 41, page: 2, perPage: 20);

        $out = $this->render('renderRows', $rows, 'lecture');

        $this->assertStringContainsString('regmus:page:42  Spring lecture', $out);
        $this->assertStringNotContainsString('Night at the museum', $out);
        $this->assertStringContainsString('Filtered page 2 by "lecture" — other pages may hold more.', $out);
    }

    #[Test]
    public function an_empty_collection_reads_as_empty_not_as_broken(): void
    {
        $rows = new ContentRows('Events', [], total: 0);

        $this->assertSame('"Events" has no records yet.', $this->render('renderRows', $rows, ''));
    }

    #[Test]
    public function search_is_case_insensitive_because_a_person_is_typing(): void
    {
        $out = $this->render('renderMap', [$this->museum()], 'VISITING');

        $this->assertStringContainsString('regmus:page:9  Visiting', $out);
    }

    #[Test]
    public function two_sites_are_listed_under_their_own_headings(): void
    {
        $clinic = $this->provider('clinic', 'City Clinic', [
            Place::site('clinic', 'City Clinic'),
            Place::page('clinic:page:1', 'Opening hours', null, 'clinic:page'),
        ]);

        $out = $this->render('renderMap', [$this->museum(), $clinic], '');

        $this->assertStringContainsString('Regional Museum', $out);
        $this->assertStringContainsString('City Clinic', $out);
        $this->assertStringContainsString('clinic:page:1  Opening hours', $out);
    }
}
