<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Handler\PayloadHandler\ContentSaveHandler;
use Semitexa\Cms\Application\Service\ContentBlockCodec;
use Semitexa\Cms\Domain\Model\BlockLayout;
use Semitexa\Cms\Domain\Model\ContentField;
use Semitexa\Cms\Domain\Model\ContentBlock;

/**
 * The page format the CMS owns.
 *
 * Two properties carry everything: a round trip must lose nothing, and a decode
 * must never throw. The second is the one that protects real sites — the value
 * comes out of somebody's table and may predate this format, may have been
 * written by a chat skill, may be half a string. A page that refuses to open
 * because its body will not parse has turned a formatting problem into a lost
 * page, which is the failure this whole epic started from.
 */
final class ContentBlockCodecTest extends TestCase
{
    private ContentBlockCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new ContentBlockCodec();
    }

    #[Test]
    public function aPageOfBlocksSurvivesTheRoundTrip(): void
    {
        $blocks = [
            ContentBlock::text('<div>Опис музею</div>', BlockLayout::of('center', null)),
            ContentBlock::image('asset-42', 'Фасад музею', BlockLayout::of('right', 'small')),
            ContentBlock::text('<div>Далі</div>'),
        ];

        $back = $this->codec->decode($this->codec->encode($blocks));

        self::assertCount(3, $back);
        self::assertSame('<div>Опис музею</div>', $back[0]->payload);
        self::assertSame('center', $back[0]->layout->align);
        self::assertSame(ContentBlock::IMAGE, $back[1]->kind);
        self::assertSame('asset-42', $back[1]->payload);
        self::assertSame('small', $back[1]->layout->size);
        self::assertTrue($back[2]->layout->isDefault());
    }

    #[Test]
    public function aPageWrittenBeforeThisFormatBecomesOneTextBlock(): void
    {
        // THE MIGRATION, and it is not a step anybody runs: every page that
        // exists today is one HTML string, and this is what it is.
        $old = '<div>Стаття, написана до того, як цей формат існував</div>';

        $blocks = $this->codec->decode($old);

        self::assertCount(1, $blocks);
        self::assertTrue($blocks[0]->isText());
        self::assertSame($old, $blocks[0]->payload);
        self::assertTrue($blocks[0]->layout->isDefault());
    }

    #[Test]
    public function thatMigrationIsLossless(): void
    {
        $old = '<div>A <strong>bold</strong> <a href="/x">link</a></div><div>Другий абзац</div>';

        self::assertSame($old, $this->codec->decode($old)[0]->payload);
    }

    #[Test]
    public function halfAStringIsStillAPage(): void
    {
        // Claims to be ours and is not readable. Keeping the text beats opening
        // an empty editor over a page somebody wrote.
        $broken = '{"format":"semitexa.cms.blocks/v1","blocks":[{"kind":"text","payl';

        $blocks = $this->codec->decode($broken);

        self::assertCount(1, $blocks);
        self::assertSame($broken, $blocks[0]->payload);
    }

    #[Test]
    public function aValueThatClaimsTheFormatWithNoReadableBlockKeepsItsText(): void
    {
        $odd = '{"format":"semitexa.cms.blocks/v1","blocks":[1,2,3]}';

        self::assertSame($odd, $this->codec->decode($odd)[0]->payload);
    }

    #[Test]
    public function oneUnreadableBlockKeepsTheWholeDocument(): void
    {
        // The dangerous shape is the MIXED one. Taking the readable blocks and
        // dropping the rest opens a page that looks almost right, and the next
        // save — for any reason at all — writes the shortened version back and
        // the dropped passage is gone from storage for good. Preserving the
        // value is the promise; a partial read does not keep it.
        $mixed = '{"format":"semitexa.cms.blocks/v1","blocks":['
            . '{"kind":"text","payload":"<p>Kept</p>"},'
            . '{"kind":"text","payload":{"not":"a string"}}'
            . ']}';

        $blocks = $this->codec->decode($mixed);

        self::assertCount(1, $blocks);
        self::assertSame($mixed, $blocks[0]->payload, 'the original document, not the half that parsed');
    }

    #[Test]
    public function anEmptyValueIsAnEmptyPageAndNotOneEmptyBlock(): void
    {
        self::assertSame([], $this->codec->decode(''));
        self::assertSame([], $this->codec->decode("   \n "));
    }

    #[Test]
    public function anUnknownLayoutFallsBackRatherThanRefusingThePage(): void
    {
        // Refusing to show a page because someone stored `align: middle` would
        // lose the text to save the alignment.
        $value = '{"format":"semitexa.cms.blocks/v1","blocks":[{"kind":"text","payload":"<div>Hi</div>",'
            . '"layout":{"align":"middle","size":"420px"}}]}';

        $layout = $this->codec->decode($value)[0]->layout;

        self::assertSame(BlockLayout::ALIGN_LEFT, $layout->align);
        self::assertSame(BlockLayout::SIZE_FULL, $layout->size);
        self::assertSame('<div>Hi</div>', $this->codec->decode($value)[0]->payload);
    }

    #[Test]
    public function anUnknownKindIsReadAsText(): void
    {
        // Forward compatibility in the direction that matters: a page written
        // by a newer version opens here with its words intact.
        $value = '{"format":"semitexa.cms.blocks/v1","blocks":[{"kind":"video","payload":"<div>Hi</div>"}]}';

        self::assertTrue($this->codec->decode($value)[0]->isText());
    }

    #[Test]
    public function anEncodedPageIsRecognisableAsOne(): void
    {
        // The marker is what tells a value that is a DOCUMENT from a value that
        // is content. Without it, an author who typed a JSON-looking paragraph
        // would have it read as a page.
        $encoded = $this->codec->encode([ContentBlock::text('<div>Hi</div>')]);

        self::assertStringContainsString('semitexa.cms.blocks/v1', $encoded);
        self::assertSame('<div>Hi</div>', $this->codec->decode($encoded)[0]->payload);
    }

    #[Test]
    public function aParagraphThatLooksLikeJsonIsStillAParagraph(): void
    {
        $typed = '{"kind":"text"} — так автор описав приклад';

        self::assertSame($typed, $this->codec->decode($typed)[0]->payload);
    }

    #[Test]
    public function anEmptyBlockKnowsItIsEmptyAndAPictureDoesNot(): void
    {
        self::assertTrue(ContentBlock::text('<div>   </div>')->isEmpty());
        self::assertFalse(ContentBlock::text('<div><img src="/os/app/cms/media/a"></div>')->isEmpty());
        self::assertFalse(ContentBlock::text('<div>слово</div>')->isEmpty());
    }

    #[Test]
    public function aBlockHoldingOnlyANonBreakingSpaceIsEmpty(): void
    {
        // What a rich-text editor leaves behind when the author clears a
        // paragraph. It is whitespace to a reader and not to trim(), so a
        // REQUIRED blocks field passed its check and saved a page with
        // nothing visible on it — reported as a successful save.
        self::assertTrue(ContentBlock::text('<div>&nbsp;</div>')->isEmpty());
        self::assertTrue(ContentBlock::text("<p>\u{00A0}</p>")->isEmpty());
        self::assertTrue(ContentBlock::text('<p>&#160;</p>')->isEmpty());
        self::assertFalse(ContentBlock::text('<p>&nbsp;слово</p>')->isEmpty(), 'a word beside it is still a word');
    }

    #[Test]
    public function aRequiredBlocksFieldOfNonBreakingSpacesIsStillMissing(): void
    {
        // The required check asks each decoded block whether it is empty, so
        // this is the same rule one level up — and it is the level where the
        // consequence is: a required body made only of `&nbsp;` passed and
        // saved a page with nothing visible on it, reported as a success.
        $document = (new ContentBlockCodec())->encode([
            ContentBlock::text('<div>&nbsp;</div>'),
            ContentBlock::text('<p>&nbsp;</p>'),
        ]);

        $hasContent = new \ReflectionMethod(ContentSaveHandler::class, 'hasContent');

        self::assertFalse($hasContent->invoke(null, $document, ContentField::BLOCKS));
    }

    #[Test]
    public function aBlockWhoseKindIsNotAStringIsNotReadableAsABlock(): void
    {
        // Present but not a string failed the image comparison and became a
        // TEXT block — and the next save wrote that back, replacing whatever
        // the document said with `"kind":"text"`. A document this cannot read
        // comes back whole, as the paragraph it is, so nothing is lost.
        $document = '{"format":"semitexa.cms.blocks/v1","blocks":[{"kind":["image"],"payload":"<p>x</p>"}]}';

        self::assertSame($document, $this->codec->decode($document)[0]->payload);
    }
}
