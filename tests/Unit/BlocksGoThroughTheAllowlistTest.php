<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentBlockCodec;
use Semitexa\Cms\Application\Service\ContentHtmlSanitizer;
use Semitexa\Cms\Domain\Model\BlockLayout;
use Semitexa\Cms\Domain\Model\ContentBlock;

/**
 * The blocks format faces the same allowlist a rich field faces.
 *
 * A new way to store markup is a new way around the sanitiser unless it is
 * taught, and this one arrives as ONE string the module never parses — which is
 * exactly the shape a bypass would take if nobody wired it in.
 */
final class BlocksGoThroughTheAllowlistTest extends TestCase
{
    private ContentHtmlSanitizer $sanitizer;
    private ContentBlockCodec $codec;

    protected function setUp(): void
    {
        $this->sanitizer = new ContentHtmlSanitizer();
        $this->codec = new ContentBlockCodec();
    }

    /** @param list<ContentBlock> $blocks @return list<ContentBlock> */
    private function through(array $blocks): array
    {
        $clean = $this->sanitizer->sanitizeValues(
            ['body' => $this->codec->encode($blocks)],
            [],
            ['body'],
        );

        return $this->codec->decode($clean->values['body']);
    }

    #[Test]
    public function aScriptInsideABlockIsStrippedAndTheWordsStay(): void
    {
        $out = $this->through([
            ContentBlock::text('<div>Текст<script>alert(1)</script> далі</div>'),
        ]);

        self::assertStringNotContainsString('<script', $out[0]->payload);
        self::assertStringContainsString('Текст', $out[0]->payload);
        self::assertStringContainsString('далі', $out[0]->payload);
    }

    #[Test]
    public function everyBlockIsCleanedAndNotOnlyTheFirst(): void
    {
        // The mistake this guards: cleaning the value as one document, or
        // stopping at the first block, leaves a page where the second
        // paragraph is the way in.
        $out = $this->through([
            ContentBlock::text('<div>Чисто</div>'),
            ContentBlock::text('<div onclick="steal()">Другий</div>'),
            ContentBlock::text('<div><iframe src="//evil"></iframe>Третій</div>'),
        ]);

        self::assertStringNotContainsString('onclick', $out[1]->payload);
        self::assertStringNotContainsString('<iframe', $out[2]->payload);
        self::assertStringContainsString('Третій', $out[2]->payload);
    }

    #[Test]
    public function theLayoutSurvivesTheCleaning(): void
    {
        // Sanitising must not cost the author their layout: it is not markup,
        // it is a property of the block the CMS owns.
        $out = $this->through([
            ContentBlock::text('<div>Текст</div>', BlockLayout::of('center', 'small')),
        ]);

        self::assertSame('center', $out[0]->layout->align);
        self::assertSame('small', $out[0]->layout->size);
    }

    #[Test]
    public function aPictureBlockPassesUntouchedBecauseItCarriesNoMarkup(): void
    {
        $out = $this->through([ContentBlock::image('asset-42', 'Фасад', BlockLayout::of('right', 'medium'))]);

        self::assertSame('asset-42', $out[0]->payload);
        self::assertSame('Фасад', $out[0]->alt);
        self::assertSame('right', $out[0]->layout->align);
    }

    #[Test]
    public function anImageTheAllowlistRefusesIsReportedFromInsideABlockToo(): void
    {
        // An article emptied of its pictures under «Збережено.» is discovered by
        // reopening it. That reporting has to reach into blocks, or the page
        // format becomes the place where the warning stops working.
        $clean = $this->sanitizer->sanitizeValues(
            ['body' => $this->codec->encode([
                ContentBlock::text('<div><img src="https://elsewhere.example/tracker.gif"></div>'),
            ])],
            [],
            ['body'],
        );

        self::assertNotSame('', $clean->notice() ?? '', 'the author has to be told what was dropped');
    }

    #[Test]
    public function aPageWrittenBeforeTheFormatIsCleanedAndKeptAsOneBlock(): void
    {
        $clean = $this->sanitizer->sanitizeValues(
            ['body' => '<div>Стара стаття<script>alert(1)</script></div>'],
            [],
            ['body'],
        );

        $blocks = $this->codec->decode($clean->values['body']);

        self::assertCount(1, $blocks);
        self::assertStringNotContainsString('<script', $blocks[0]->payload);
        self::assertStringContainsString('Стара стаття', $blocks[0]->payload);
    }

    #[Test]
    public function anEmptyPageStaysEmptyRatherThanBecomingAnEncodedNothing(): void
    {
        $clean = $this->sanitizer->sanitizeValues(['body' => ''], [], ['body']);

        self::assertSame('', $clean->values['body']);
    }
}
