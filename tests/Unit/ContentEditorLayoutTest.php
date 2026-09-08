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
 * How a draft is split between the canvas and the properties panel.
 *
 * The editor stopped being a flat list of equal-weight fields: the name of the
 * record and the record itself are on the canvas, everything else waits behind
 * «Властивості». The split is by field KIND, not by name, so these pin the rule
 * rather than one draft's field list.
 */
final class ContentEditorLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        ModuleAssetRegistry::setModuleRegistry(new ModuleRegistry());
    }

    #[Test]
    public function the_first_line_is_the_title_and_the_first_rich_field_is_the_body(): void
    {
        $html = $this->render([
            ContentField::line('slug', 'Адреса', 'about'),
            ContentField::line('name', 'Назва', 'Про нас'),
            ContentField::html('body', 'Текст', '<p>Hi</p>'),
        ]);

        // First LINE on the canvas, as the document title.
        self::assertStringContainsString('<input class="doctitle" type="text" name="slug"', $html);
        // First HTML on the canvas, as the document body.
        self::assertStringContainsString('<div class="writing">', $html);
        self::assertStringContainsString('<trix-editor input="rich-2-body"', $html);
        // The second LINE was neither, so it is in the drawer.
        self::assertStringContainsString('<aside class="panel"', $html);
        self::assertStringContainsString('name="name"', $html);
    }

    /** No panel, and no button opening one, when nothing belongs in it. */
    #[Test]
    public function a_draft_with_nothing_left_over_has_no_panel(): void
    {
        $html = $this->render([
            ContentField::line('name', 'Назва', 'Про нас'),
            ContentField::html('body', 'Текст', ''),
        ]);

        self::assertStringNotContainsString('<aside class="panel"', $html);
        self::assertStringNotContainsString('data-act="props"', $html);
    }

    /**
     * A required field inside the closed drawer must not be browser-enforced.
     *
     * REGRESSION. The panel is `display:none` until the author opens it, and a
     * hidden control cannot be focused — so a `required` attribute there makes
     * the browser refuse the submit, focus nothing and say nothing. «Зберегти»
     * silently stops working, with no way to discover why.
     *
     * MEASURED in Chrome: with the panel closed `form.reportValidity()` is
     * false and `document.activeElement` does not move; with it open the same
     * call focuses the field and shows its bubble. Only the second is an error
     * a person can act on.
     *
     * So the drawer announces the constraint to assistive technology and lets
     * ContentSaveHandler::missingRequired() — which names the empty field —
     * be the gate. The canvas title keeps the real attribute, because that
     * control is always visible and the browser can point straight at it.
     */
    #[Test]
    public function a_required_field_in_the_drawer_does_not_block_the_save(): void
    {
        $html = $this->render([
            ContentField::line('name', 'Назва', 'Про нас', true),
            ContentField::html('body', 'Текст', ''),
            ContentField::text('summary', 'Опис', '', true),
        ]);

        $panel = substr($html, (int) strpos($html, '<aside class="panel"'));

        self::assertStringContainsString('name="summary"', $panel);
        self::assertStringContainsString('aria-required="true"', $panel);
        self::assertDoesNotMatchRegularExpression('/<textarea[^>]*\srequired[\s>]/', $panel);

        // The visible title is still the browser's to enforce.
        self::assertMatchesRegularExpression('/<input class="doctitle"[^>]*\srequired[\s>]/', $html);
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
