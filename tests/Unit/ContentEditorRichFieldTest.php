<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentEditorPage;
use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\Asset\ModuleAssetRegistry;

/**
 * The rich-text field.
 *
 * `ContentField::HTML` existed as a kind long before anything could edit it —
 * the page rendered it as a plain textarea, so an author was handed the full
 * power of HTML and no tool for it. This pins the shape that replaced that:
 * a hidden input carrying the value in both directions, with the vendored
 * editor bound to it by id.
 */
final class ContentEditorRichFieldTest extends TestCase
{
    protected function setUp(): void
    {
        ModuleAssetRegistry::setModuleRegistry(new ModuleRegistry());
    }

    #[Test]
    public function a_rich_field_is_an_editor_bound_to_a_hidden_input(): void
    {
        $html = $this->render([ContentField::html('body', 'Текст', '<p>Hi</p>')]);

        self::assertStringContainsString('<input id="rich-body" type="hidden" name="body"', $html);
        self::assertStringContainsString('<trix-editor input="rich-body"', $html);
    }

    /**
     * The value lives in an ATTRIBUTE, so it must be attribute-escaped. Leaking
     * a raw tag here would end the attribute early and put author content into
     * the markup as markup — the editor would be the injection point it exists
     * to remove.
     */
    #[Test]
    public function the_stored_markup_is_escaped_into_the_attribute(): void
    {
        $html = $this->render([ContentField::html('body', 'Текст', '<p>Музей у <b>Львові</b>.</p>')]);

        self::assertStringContainsString('value="&lt;p&gt;Музей у &lt;b&gt;Львові&lt;/b&gt;.&lt;/p&gt;"', $html);
        self::assertStringNotContainsString('value="<p>', $html);
    }

    #[Test]
    public function a_plain_text_field_is_still_a_textarea(): void
    {
        $html = $this->render([ContentField::text('note', 'Нотатка', 'plain')]);

        self::assertStringContainsString('<textarea name="note">plain</textarea>', $html);
        self::assertStringNotContainsString('trix-editor', $html);
    }

    /** A draft with nothing rich in it must not pay for the editor. */
    #[Test]
    public function the_editor_assets_load_only_where_a_rich_field_exists(): void
    {
        $without = $this->render([ContentField::line('title', 'Заголовок', 'X')]);
        self::assertStringNotContainsString('vendor/trix', $without);

        $with = $this->render([ContentField::html('body', 'Текст', '')]);
        self::assertStringContainsString('/assets/cms/vendor/trix/trix.css', $with);
        self::assertStringContainsString('/assets/cms/vendor/trix/trix.umd.min.js', $with);
        // Ours, and loaded after the bundle it binds listeners onto.
        self::assertStringContainsString('/assets/cms/js/content-editor.js', $with);
        self::assertLessThan(
            strpos($with, '/assets/cms/js/content-editor.js'),
            strpos($with, '/assets/cms/vendor/trix/trix.umd.min.js'),
        );
    }

    /**
     * `required` belongs on the input, not on <trix-editor>: the custom element
     * is not a form control the browser validates, so the constraint would be
     * decoration there.
     */
    #[Test]
    public function required_is_carried_by_the_control_the_browser_validates(): void
    {
        $html = $this->render([ContentField::html('body', 'Текст', '', true)]);

        self::assertMatchesRegularExpression('/<input id="rich-body"[^>]*\srequired>/', $html);
        self::assertDoesNotMatchRegularExpression('/<trix-editor[^>]*\srequired/', $html);
    }

    /** @param list<ContentField> $fields */
    private function render(array $fields): string
    {
        return (new ContentEditorPage())->render(
            new ContentDraft(ref: 'r', title: 'T', fields: $fields, publicUrl: null),
            'csrf',
        );
    }
}
