<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentBlockCodec;
use Semitexa\Cms\Application\Service\ContentEditorPage;
use Semitexa\Cms\Domain\Model\BlockLayout;
use Semitexa\Cms\Domain\Model\ContentBlock;
use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\Asset\ModuleAssetRegistry;
use Semitexa\Ssr\Application\Service\Asset\ModuleAssetResolver;

/**
 * A page of blocks, as the author meets it.
 *
 * The rule that shapes this markup: ONE named input for the whole page.
 * Everything an author touches is DOM the console owns and collects on submit,
 * because save() takes array<string, string> keyed by DECLARED field names — a
 * per-block input with a name would arrive as a field no module declared.
 */
final class ContentEditorBlocksFieldTest extends TestCase
{
    private mixed $resolverSnapshot = null;

    protected function setUp(): void
    {
        // A FRESH resolver is installed before anything is mutated, and the
        // one that was there is put back afterwards.
        //
        // setModuleRegistry() writes THROUGH to the resolver object, so
        // keeping a reference to it and restoring that reference would restore
        // a pointer to state this class had already changed. Leaving the
        // replacement installed is how a later test fails only when this one
        // happens to run first.
        $property = new \ReflectionProperty(ModuleAssetRegistry::class, 'resolver');
        $this->resolverSnapshot = $property->getValue();
        $property->setValue(null, new ModuleAssetResolver());

        ModuleAssetRegistry::setModuleRegistry(new ModuleRegistry());
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ModuleAssetRegistry::class, 'resolver'))
            ->setValue(null, $this->resolverSnapshot);
    }

    /**
     * Only the blocks an author can see and touch.
     *
     * The control also renders an inert <template> per kind, so that an empty
     * page can gain its first block — counting across the whole document would
     * count those too, and the assertions below are about what is on screen.
     */
    private function liveBlocks(string $html): string
    {
        $start = strpos($html, '<div class="blocks"');
        $end = strpos($html, '<template', $start === false ? 0 : $start);

        self::assertNotFalse($start, 'the blocks list must be rendered');

        return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
    }

    private function render(string $value): string
    {
        $draft = new ContentDraft('demo:page:1', 'Сторінка', [
            ContentField::line('title', 'Назва', 'Сторінка'),
            ContentField::blocks('body', 'Текст', $value),
        ]);

        return (new ContentEditorPage())->render($draft, 'csrf-token');
    }

    #[Test]
    public function a_second_blocks_field_gets_its_own_wrapper_and_its_own_buttons(): void
    {
        // The parts of this control used to be siblings, so an add button had
        // no element meaning "my field": the client fell back to the first
        // [data-blocks] in the DOCUMENT and «+ Текст» in the second field wrote
        // into the first. The wrapper is what makes the association exist.
        $codec = new ContentBlockCodec();
        $draft = new ContentDraft('demo:page:1', 'Сторінка', [
            ContentField::blocks('body', 'Текст', $codec->encode([ContentBlock::text('<div>Тіло</div>')])),
            ContentField::blocks('aside', 'Збоку', $codec->encode([ContentBlock::text('<div>Збоку</div>')])),
        ]);

        $html = (new ContentEditorPage())->render($draft, 'csrf-token');

        self::assertSame(2, substr_count($html, 'class="blocks-field"'), 'one wrapper per field');
        self::assertSame(2, substr_count($html, 'class="blocks__add"'));
        self::assertStringContainsString('name="body"', $html);
        self::assertStringContainsString('name="aside"', $html);

        // Each wrapper holds exactly one list and both templates, so nothing
        // inside it has to reach out to the document to find its own parts.
        foreach ($this->wrappers($html) as $wrapper) {
            self::assertSame(1, substr_count($wrapper, 'data-blocks='));
            self::assertSame(1, substr_count($wrapper, 'data-block-template="text"'));
            self::assertSame(1, substr_count($wrapper, 'data-block-template="image"'));
            self::assertSame(1, substr_count($wrapper, 'data-block-add="text"'));
        }
    }

    #[Test]
    public function a_blocks_field_that_is_not_the_body_is_not_wrapped_in_a_label(): void
    {
        // The BODY field is rendered by the writing path and was always fine.
        // A SECOND blocks field goes through the ordinary field path, which
        // wrapped everything but HTML and IMAGE in a <label> — and a label
        // forwards a click anywhere inside it to the first labelable control
        // it contains. Here that is a move-up button, so clicking the passage
        // pressed it.
        $codec = new ContentBlockCodec();
        $draft = new ContentDraft('demo:page:1', 'Сторінка', [
            ContentField::blocks('body', 'Текст', $codec->encode([ContentBlock::text('<div>Тіло</div>')])),
            ContentField::blocks('aside', 'Збоку', $codec->encode([ContentBlock::text('<div>Збоку</div>')])),
        ]);

        $html = (new ContentEditorPage())->render($draft, 'csrf-token');

        self::assertStringContainsString('<div class="field"><span>Збоку</span>', $html);
        self::assertStringNotContainsString('<label><span>Збоку</span>', $html);

        $aside = $this->wrappers($html)[1];
        self::assertStringContainsString('name="aside"', $aside);
    }

    /** @return list<string> */
    private function wrappers(string $html): array
    {
        $out = [];
        $offset = 0;

        while (($at = strpos($html, 'class="blocks-field"', $offset)) !== false) {
            $next = strpos($html, 'class="blocks-field"', $at + 1);
            $out[] = substr($html, $at, $next === false ? null : $next - $at);
            $offset = $at + 1;
        }

        return $out;
    }

    #[Test]
    public function the_page_submits_through_exactly_one_named_input(): void
    {
        $html = $this->render((new ContentBlockCodec())->encode([
            ContentBlock::text('<div>Перший</div>'),
            ContentBlock::text('<div>Другий</div>'),
        ]));

        self::assertSame(1, substr_count($html, 'name="body"'), 'one value for the page, whatever it holds');
        self::assertStringContainsString('type="hidden" name="body"', $html);
    }

    #[Test]
    public function every_block_gets_its_own_editor_and_its_own_controls(): void
    {
        $html = $this->render((new ContentBlockCodec())->encode([
            ContentBlock::text('<div>Перший</div>'),
            ContentBlock::text('<div>Другий</div>'),
        ]));

        $live = $this->liveBlocks($html);

        self::assertSame(2, substr_count($live, '<article class="block"'));
        self::assertSame(2, substr_count($live, '<trix-editor'));
        self::assertSame(2, substr_count($live, 'data-block-remove'));
        self::assertSame(2, substr_count($live, 'data-block-move="up"'));
    }

    #[Test]
    public function the_layout_an_author_chose_comes_back_selected(): void
    {
        $html = $this->render((new ContentBlockCodec())->encode([
            ContentBlock::text('<div>Текст</div>', BlockLayout::of('center', 'small')),
        ]));

        self::assertStringContainsString('<option value="center" selected>', $html);
        self::assertStringContainsString('<option value="small" selected>', $html);
    }

    #[Test]
    public function a_picture_block_asks_for_its_alt_text(): void
    {
        // An image with no description is an accessibility defect the console
        // would be shipping by design, so the field is beside the picture and
        // not behind a panel.
        $html = $this->render((new ContentBlockCodec())->encode([
            ContentBlock::image('asset-42', 'Фасад музею'),
        ]));

        self::assertStringContainsString('data-block-alt', $html);
        self::assertStringContainsString('value="Фасад музею"', $html);
        self::assertStringContainsString('Опис для тих, хто не бачить зображення', $html);
    }

    #[Test]
    public function a_page_written_before_this_format_opens_as_one_passage(): void
    {
        // What every existing page is. The author sees exactly what they wrote
        // and can split it whenever they like — nothing is converted behind
        // their back.
        $html = $this->render('<div>Стаття, написана до блоків</div>');

        self::assertSame(1, substr_count($this->liveBlocks($html), '<article class="block"'));
        self::assertStringContainsString('Стаття, написана до блоків', $html);
    }

    #[Test]
    public function an_author_can_add_either_kind(): void
    {
        $html = $this->render('');

        self::assertStringContainsString('data-block-add="text"', $html);
        self::assertStringContainsString('data-block-add="image"', $html);
    }

    #[Test]
    public function the_per_block_inputs_are_nameless_so_nothing_extra_is_submitted(): void
    {
        // The mistake this guards: a named input per block posts fields the
        // module never declared, and save() is keyed by declared names.
        $html = $this->render((new ContentBlockCodec())->encode([
            ContentBlock::text('<div>Перший</div>'),
            ContentBlock::image('asset-1', 'Опис'),
        ]));

        self::assertSame(1, substr_count($html, 'name="body"'));
        self::assertStringNotContainsString('name="body[', $html);
    }

    #[Test]
    public function a_page_of_blocks_loads_the_editor_and_the_script_that_drives_it(): void
    {
        // Nearly shipped without this: the editor was loaded only for an HTML
        // BODY, so a page of blocks rendered every control and had no editor
        // behind any of them — the author would have seen a page they could
        // not type into.
        $html = $this->render('<div>Текст</div>');

        self::assertStringContainsString('trix.umd.min.js', $html);
        self::assertStringContainsString('content-blocks.js', $html);
    }

    #[Test]
    public function a_draft_with_no_editable_markup_loads_neither(): void
    {
        $draft = new ContentDraft('demo:page:1', 'Сторінка', [
            ContentField::line('title', 'Назва', 'Сторінка'),
            ContentField::date('when', 'Дата', '2026-09-16'),
        ]);

        $html = (new ContentEditorPage())->render($draft, 'csrf-token');

        // The page must have rendered before its lack of Trix means anything.
        self::assertStringContainsString('name="title"', $html, 'the editor rendered its fields');
        self::assertStringNotContainsString('trix.umd.min.js', $html);
    }

    #[Test]
    public function the_page_is_on_the_canvas_and_not_behind_settings(): void
    {
        // Caught by reading split(), not by a test: it recognised only an HTML
        // body, so a page of blocks rendered inside «Властивості» — the page's
        // own content in a settings drawer — while every assertion passed.
        $html = $this->render('<div>Текст</div>');

        $writing = strpos($html, 'class="writing"');
        $panel = strpos($html, 'class="panel"');
        $blocks = strpos($html, 'data-blocks=');

        self::assertNotFalse($writing);
        self::assertNotFalse($blocks);
        self::assertGreaterThan($writing, $blocks, 'the page belongs on the canvas');
        if ($panel !== false) {
            self::assertLessThan($panel, $blocks, 'and before the settings drawer');
        }
    }

    #[Test]
    public function an_empty_page_still_offers_a_block_to_add(): void
    {
        // The review finding, at the level where it is cheapest to pin: without
        // a template there is nothing to clone, and both add buttons do nothing
        // on a page that has no blocks yet.
        $html = $this->render('');

        self::assertSame(0, substr_count($this->liveBlocks($html), '<article class="block"'));
        self::assertStringContainsString('<template data-block-template="text">', $html);
        self::assertStringContainsString('<template data-block-template="image">', $html);
    }

    #[Test]
    public function the_writing_surface_still_says_where_appearance_comes_from(): void
    {
        $html = $this->render('<div>Текст</div>');

        self::assertStringContainsString('Колір і шрифт бере оформлення сайту', $html);
    }
}
