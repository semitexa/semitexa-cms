<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Domain\Model\Place;
use Semitexa\Weave\Domain\Enum\NodeKind;

final class PlaceTest extends TestCase
{
    #[Test]
    public function a_page_opens_an_editor_and_a_collection_opens_a_grid(): void
    {
        // Every view asks this first, so the answer is stored rather than
        // inferred from which of editor/source happens to be set.
        $page = Place::page('regmus:page:7', 'Контакти', editor: 'regmus:page');
        $collection = Place::collection('regmus:events', 'Події', source: 'regmus:pages?type=event');

        self::assertSame('editor', $page->nodeProperties()['opens']);
        self::assertSame('regmus:page', $page->nodeProperties()['editor']);
        self::assertSame('grid', $collection->nodeProperties()['opens']);
        self::assertSame('regmus:pages?type=event', $collection->nodeProperties()['source']);
    }

    #[Test]
    public function every_place_is_marked_as_belonging_to_a_site(): void
    {
        // The store also holds the person's own graph; without this marker a
        // view cannot tell a museum's page from someone's note about it.
        self::assertSame('site', Place::page('r:1', 'A')->nodeProperties()['origin']);
        self::assertSame('site', Place::collection('r:c', 'B', 'src')->nodeProperties()['origin']);
        self::assertSame('site', Place::site('r', 'C')->nodeProperties()['origin'] ?? null);
    }

    #[Test]
    public function the_three_sorts_carry_the_kinds_the_graph_understands(): void
    {
        self::assertSame(NodeKind::Page, Place::page('r:1', 'A')->kind);
        self::assertSame(NodeKind::Collection, Place::collection('r:c', 'B', 'src')->kind);
        self::assertSame(NodeKind::Site, Place::site('r', 'C')->kind);
        self::assertTrue(Place::collection('r:c', 'B', 'src')->isCollection());
        self::assertFalse(Place::page('r:1', 'A')->isCollection());
    }

    #[Test]
    public function a_collection_without_a_source_is_refused(): void
    {
        // It would render an empty grid and look like a bug in the content.
        $this->expectException(\InvalidArgumentException::class);

        Place::collection('regmus:events', 'Події', source: '  ');
    }

    #[Test]
    public function a_place_without_a_ref_is_refused(): void
    {
        // The ref is what makes it the same place after someone renames it.
        $this->expectException(\InvalidArgumentException::class);

        Place::page('   ', 'Контакти');
    }

    #[Test]
    public function declared_properties_survive_alongside_the_derived_ones(): void
    {
        $place = Place::page('regmus:page:7', 'Контакти', properties: ['sef' => 'contacts'], order: 3);

        $properties = $place->nodeProperties();
        self::assertSame('contacts', $properties['sef']);
        self::assertSame(3, $properties['order']);
    }
}
