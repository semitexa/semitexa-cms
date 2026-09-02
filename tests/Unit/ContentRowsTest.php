<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentSurfaceRegistry;
use Semitexa\Cms\Domain\Model\ContentRow;
use Semitexa\Cms\Domain\Model\ContentRows;

final class ContentRowsTest extends TestCase
{
    #[Test]
    public function paging_counts_the_last_partial_page(): void
    {
        // 85 events at 25 a page is four pages, not three: the museum's largest
        // collection is exactly the case where an off-by-one hides records.
        $rows = new ContentRows('Події', [], total: 85, page: 1, perPage: 25);

        self::assertSame(4, $rows->pages());
        self::assertFalse($rows->hasPrevious());
        self::assertTrue($rows->hasNext());
    }

    #[Test]
    public function the_last_page_offers_no_next(): void
    {
        $rows = new ContentRows('Події', [], total: 85, page: 4, perPage: 25);

        self::assertTrue($rows->hasPrevious());
        self::assertFalse($rows->hasNext());
    }

    #[Test]
    public function an_empty_collection_is_still_one_page(): void
    {
        // Zero pages would render "0 / 0" and a pager that goes nowhere.
        $rows = new ContentRows('Новини', [], total: 0);

        self::assertSame(1, $rows->pages());
        self::assertFalse($rows->hasNext());
    }

    #[Test]
    public function a_row_carries_only_what_identifies_it(): void
    {
        $row = new ContentRow('regmus:page:135', 'Врятована спадщина', ['19.05.2026', 'vriatovana-spadshchyna-137']);

        self::assertSame('regmus:page:135', $row->ref);
        self::assertCount(2, $row->meta);
    }

    #[Test]
    public function the_source_query_is_handed_back_as_the_module_wrote_it(): void
    {
        // The CMS does not interpret these; it carries them to whoever declared
        // the collection.
        self::assertSame(['type' => 'event'], ContentSurfaceRegistry::filtersOf('regmus:pages?type=event'));
        self::assertSame(['category' => '11'], ContentSurfaceRegistry::filtersOf('regmus:pages?category=11'));
        self::assertSame([], ContentSurfaceRegistry::filtersOf('regmus:pages'));
        self::assertSame([], ContentSurfaceRegistry::filtersOf('  '));
    }
}
