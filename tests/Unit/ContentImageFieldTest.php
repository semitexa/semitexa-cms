<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentEditorPage;
use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;

/**
 * A page's cover, from the editor.
 *
 * The open question was whether the value is a bare asset id or a value object
 * carrying a preview URL and dimensions. It is the id, and the contract decided
 * it: ContentEditorInterface::save() hands a module array<string, string>, so a
 * richer value could not ride it without changing the contract for every module
 * that already implements it. A URL stored beside the id would also be a second
 * copy of a fact the media service owns — and the console media route exists
 * precisely so that moving the storage does not invalidate stored content.
 */
final class ContentImageFieldTest extends TestCase
{
    #[Test]
    public function the_value_is_the_asset_id_and_the_url_is_derived(): void
    {
        $field = ContentField::image('cover', 'Обкладинка', 'a1b2c3');

        $this->assertSame('a1b2c3', $field->value);
        $this->assertSame(ContentField::IMAGE, $field->kind);
        $this->assertSame('/os/app/cms/media/a1b2c3', $field->previewUrl());
    }

    #[Test]
    public function no_image_yet_has_no_url_to_point_at(): void
    {
        // An <img src=""> re-requests the page it is on. Empty means empty.
        $this->assertSame('', ContentField::image('cover', 'Обкладинка')->previewUrl());
    }

    #[Test]
    public function only_an_image_field_resolves_a_preview(): void
    {
        // The same string on a text field is a sentence, not an asset id.
        $this->assertSame('', ContentField::line('title', 'Title', 'a1b2c3')->previewUrl());
    }

    #[Test]
    public function an_asset_id_is_escaped_into_the_url(): void
    {
        $this->assertSame(
            '/os/app/cms/media/a%2F..%2Fb',
            ContentField::image('cover', 'C', 'a/../b')->previewUrl(),
        );
    }

    #[Test]
    public function the_control_posts_the_id_and_can_say_take_it_off(): void
    {
        $html = (new ContentEditorPage())->render(
            new ContentDraft('demo:1', 'A', [ContentField::image('cover', 'Обкладинка', 'a1b2c3')]),
            'tok',
        );

        // The hidden input is what posts, so the field still submits with the
        // client script blocked.
        $this->assertStringContainsString('type="hidden" name="cover" value="a1b2c3"', $html);
        $this->assertStringContainsString('/os/app/cms/media/a1b2c3', $html);
        // With an image present the author can replace it or take it off.
        $this->assertStringContainsString('cover__clear', $html);
        $this->assertStringContainsString('Замінити', $html);
    }

    #[Test]
    public function an_empty_cover_offers_choosing_and_hides_removing(): void
    {
        $html = (new ContentEditorPage())->render(
            new ContentDraft('demo:1', 'A', [ContentField::image('cover', 'Обкладинка')]),
            'tok',
        );

        $this->assertStringContainsString('Вибрати зображення', $html);
        $this->assertStringContainsString('cover__clear" hidden', $html);
    }

    #[Test]
    public function clearing_writes_an_empty_value_rather_than_dropping_the_field(): void
    {
        $js = file_get_contents(__DIR__ . '/../../src/Application/Static/js/content-shell.js');
        $this->assertIsString($js);

        // A field that vanishes from the post cannot be told apart from one
        // nobody touched — the difference between "leave the picture" and "take
        // the picture off". The contract says an image field arrives EMPTY when
        // cleared, so the input stays and its value goes blank.
        $this->assertStringContainsString("coverShow(button.closest('.cover'), '', '')", $js);
        $this->assertStringContainsString('hidden.value = assetId;', $js);
    }

    #[Test]
    public function the_uploader_hands_back_the_id_the_field_stores(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../src/Application/Handler/PayloadHandler/ContentImageUploadHandler.php',
        );
        $this->assertIsString($source);

        // Markup embeds the URL and a field stores the id; the handler returns
        // both, and builds the URL through previewUrl() rather than spelling
        // the route a second time where the two could drift.
        $this->assertStringContainsString("'assetId' => \$reference->assetId,", $source);
        $this->assertStringContainsString('->previewUrl()', $source);
        $this->assertStringNotContainsString("'/os/app/cms/media/' . rawurlencode", $source);
    }
}
