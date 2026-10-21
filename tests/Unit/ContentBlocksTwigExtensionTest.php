<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentBlockCodec;
use Semitexa\Cms\Application\Service\Twig\ContentBlocksTwigExtension;
use Semitexa\Cms\Domain\Model\BlockLayout;
use Semitexa\Cms\Domain\Model\ContentBlock;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;

/**
 * A template had nothing to call for a page written as blocks: printing the
 * field gave the visitor the stored JSON. cms_blocks() renders it, and pulls in
 * the default block stylesheet only when a block carries a chosen layout.
 */
final class ContentBlocksTwigExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        AssetCollectorStore::reset();
    }

    protected function tearDown(): void
    {
        AssetCollectorStore::reset();
    }

    #[Test]
    public function a_page_written_before_blocks_prints_as_it_did_and_needs_no_stylesheet(): void
    {
        $old = '<div>Про музей</div><p>Другий абзац</p>';

        self::assertSame($old, (string) (new ContentBlocksTwigExtension())->render($old));
        self::assertSame([], self::required());
    }

    #[Test]
    public function stored_blocks_print_as_markup_with_their_layout_and_the_stylesheet(): void
    {
        $stored = (new ContentBlockCodec())->encode([
            ContentBlock::text('<p>Вступ</p>'),
            ContentBlock::image('asset-7', 'Фасад', BlockLayout::of('right', 'small')),
        ]);

        $html = (string) (new ContentBlocksTwigExtension())->render($stored);

        self::assertStringNotContainsString('"payload"', $html, 'not the stored JSON');
        self::assertStringContainsString('<p>Вступ</p>', $html);
        self::assertStringContainsString('<figure class="cms-block" data-align="right" data-size="small">', $html);
        self::assertSame([ContentBlocksTwigExtension::STYLESHEET], self::required());
    }

    #[Test]
    public function an_empty_field_prints_nothing(): void
    {
        self::assertSame('', (string) (new ContentBlocksTwigExtension())->render(null));
        self::assertSame('', (string) (new ContentBlocksTwigExtension())->render(''));
    }

    /** @return list<string> */
    private static function required(): array
    {
        return array_values(array_map(static fn ($entry): string => $entry->key, AssetCollectorStore::get()->resolve()));
    }
}
