<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentBlockCodec;
use Semitexa\Cms\Application\Service\ContentBlockRenderer;
use Semitexa\Cms\Domain\Model\BlockLayout;
use Semitexa\Cms\Domain\Model\ContentBlock;

/**
 * What a visitor gets.
 *
 * The property this file exists for is the migration one: a page written before
 * the blocks format decodes to a single default block and must render exactly
 * what it rendered before. A migration nobody can see is the only kind worth
 * running on somebody's live site — and the site this came from lost a whole
 * section to a migration already.
 */
final class ContentBlockRendererTest extends TestCase
{
    private ContentBlockRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ContentBlockRenderer();
    }

    #[Test]
    public function aMigratedPageRendersByteForByteWhatItRenderedBefore(): void
    {
        $old = '<div>Про музей</div><div>Другий абзац із <strong>наголосом</strong></div>';

        $rendered = $this->renderer->render((new ContentBlockCodec())->decode($old));

        self::assertSame($old, $rendered);
    }

    #[Test]
    public function aChosenLayoutBecomesNamesForTheSkin(): void
    {
        $html = $this->renderer->render([
            ContentBlock::text('<div>Центр</div>', BlockLayout::of('center', null)),
        ]);

        self::assertSame('<div data-align="center" data-size="full"><div>Центр</div></div>', $html);
    }

    #[Test]
    public function nothingAboutAppearanceIsStoredWithTheWords(): void
    {
        // The line the whole design draws: the skin decides what "center" looks
        // like. A stored style would survive a skin swap and outlive the design
        // it was chosen in.
        $html = $this->renderer->render([
            ContentBlock::text('<div>Текст</div>', BlockLayout::of('right', 'small')),
        ]);

        self::assertStringNotContainsString('style=', $html);
        self::assertStringNotContainsString('class=', $html);
        self::assertStringContainsString('data-align="right"', $html);
    }

    #[Test]
    public function aPictureIsRenderedFromItsAssetIdAndNeverFromAStoredUrl(): void
    {
        $html = $this->renderer->render([
            ContentBlock::image('asset 42/x', 'Фасад музею', BlockLayout::of('center', 'medium')),
        ]);

        self::assertStringContainsString('src="/os/app/cms/media/asset%2042%2Fx"', $html);
        self::assertStringContainsString('alt="Фасад музею"', $html);
        self::assertStringContainsString('<figure data-align="center" data-size="medium">', $html);
    }

    #[Test]
    public function aDecorativePictureSaysSoRatherThanSayingNothing(): void
    {
        // An empty alt tells a screen reader to skip it; a MISSING alt tells it
        // to guess, and the guess is the file name.
        $html = $this->renderer->render([ContentBlock::image('asset-1')]);

        self::assertStringContainsString('alt=""', $html);
    }

    #[Test]
    public function altTextCannotLeaveItsAttribute(): void
    {
        $html = $this->renderer->render([ContentBlock::image('a', '"><script>alert(1)</script>')]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&quot;&gt;', $html);
    }

    #[Test]
    public function anEmptyPassageIsNotAGapOnThePage(): void
    {
        $html = $this->renderer->render([
            ContentBlock::text('<div>Перший</div>'),
            ContentBlock::text('<div>   </div>'),
            ContentBlock::text('<div>Третій</div>'),
        ]);

        self::assertSame('<div>Перший</div><div>Третій</div>', $html);
    }

    #[Test]
    public function anImageBlockWithNoAssetRendersNothingRatherThanABrokenPicture(): void
    {
        self::assertSame('', $this->renderer->render([ContentBlock::image('')]));
    }

    #[Test]
    public function blocksKeepTheOrderTheAuthorPutThemIn(): void
    {
        $html = $this->renderer->render([
            ContentBlock::text('<div>1</div>'),
            ContentBlock::image('a', 'Картинка'),
            ContentBlock::text('<div>2</div>'),
        ]);

        self::assertLessThan(strpos($html, 'media/a'), strpos($html, '<div>1</div>'));
        self::assertLessThan(strpos($html, '<div>2</div>'), strpos($html, 'media/a'));
    }
}
