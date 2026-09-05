<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Domain\Model\ContentSeo;

/**
 * The one rule that decides whether anyone will trust a generated meta layer:
 * a person's words survive the next generation, and nothing else does.
 */
final class ContentSeoTest extends TestCase
{
    #[Test]
    public function a_fresh_page_has_nothing_and_is_open_to_every_generated_field(): void
    {
        $seo = new ContentSeo(ref: 'page:about');

        self::assertTrue($seo->isEmpty());
        self::assertSame(ContentSeo::GENERATED_FIELDS, $seo->openToGeneration());
    }

    #[Test]
    public function generation_fills_the_fields_and_records_what_it_read(): void
    {
        $seo = (new ContentSeo(ref: 'page:about'))->withGenerated([
            'title' => 'About the museum',
            'description' => 'A regional museum in Lviv, open Tuesday to Sunday.',
            'ogTitle' => 'About the museum',
            'ogDescription' => 'A regional museum in Lviv.',
            'jsonLd' => '{"@type":"Museum"}',
        ], 'hash-1');

        self::assertSame('A regional museum in Lviv, open Tuesday to Sunday.', $seo->description);
        self::assertSame('hash-1', $seo->sourceHash);
        self::assertFalse($seo->isEmpty());
    }

    /**
     * The failure that would make the whole feature untrustworthy: an editor
     * writes a description, the page is saved, and the generator quietly
     * replaces it. Once that happens once, nobody edits the field again.
     */
    #[Test]
    public function a_field_a_person_wrote_survives_the_next_generation(): void
    {
        $seo = (new ContentSeo(ref: 'page:about'))
            ->withGenerated(['description' => 'generated once', 'title' => 'generated title'], 'hash-1')
            ->withAuthored(['description' => 'the description we actually want']);

        $after = $seo->withGenerated([
            'description' => 'generated again',
            'title' => 'regenerated title',
        ], 'hash-2');

        self::assertSame('the description we actually want', $after->description);
        self::assertSame('regenerated title', $after->title, 'fields nobody claimed still regenerate');
    }

    #[Test]
    public function an_authored_field_is_no_longer_offered_to_the_generator(): void
    {
        $seo = (new ContentSeo(ref: 'page:about'))->withAuthored(['description' => 'mine']);

        self::assertTrue($seo->isAuthored('description'));
        self::assertNotContains('description', $seo->openToGeneration());
        self::assertContains('title', $seo->openToGeneration());
    }

    /**
     * Clearing must hand the field back, not pin a deliberate empty string —
     * otherwise emptying a box would switch the generator off forever with no
     * way to tell and no way back.
     */
    #[Test]
    public function clearing_an_authored_field_releases_it_back_to_the_generator(): void
    {
        $seo = (new ContentSeo(ref: 'page:about'))
            ->withAuthored(['description' => 'mine'])
            ->withAuthored(['description' => '   ']);

        self::assertFalse($seo->isAuthored('description'));
        self::assertContains('description', $seo->openToGeneration());
        self::assertSame('generated later', $seo->withGenerated(['description' => 'generated later'], 'h')->description);
    }

    /**
     * Indexing policy is a decision about the site, not an observation about
     * the text. A model guessing at it would be inventing policy.
     */
    #[Test]
    public function indexing_policy_and_the_social_image_are_never_generated(): void
    {
        $seo = (new ContentSeo(ref: 'page:about'))
            ->withAuthored(['canonical' => 'https://example.org/about', 'robots' => 'noindex', 'ogImage' => '/img/a.jpg']);

        $after = $seo->withGenerated([
            'canonical' => 'https://evil.example/hijack',
            'robots' => 'index,follow',
            'ogImage' => '/img/generated.jpg',
            'description' => 'fine to generate',
        ], 'hash-2');

        self::assertSame('https://example.org/about', $after->canonical);
        self::assertSame('noindex', $after->robots);
        self::assertSame('/img/a.jpg', $after->ogImage);
        self::assertSame('fine to generate', $after->description);
        self::assertNotContains('canonical', ContentSeo::GENERATED_FIELDS);
        self::assertNotContains('robots', ContentSeo::GENERATED_FIELDS);
    }

    #[Test]
    public function the_meta_is_stale_once_the_content_it_was_written_from_moves(): void
    {
        $seo = (new ContentSeo(ref: 'page:about'))->withGenerated(['description' => 'd'], 'hash-1');

        self::assertFalse($seo->isStale('hash-1'));
        self::assertTrue($seo->isStale('hash-2'));
        self::assertFalse(
            (new ContentSeo(ref: 'page:about'))->isStale('hash-1'),
            'a page with no meta yet is not stale — it is simply unwritten',
        );
    }
}
