<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\BlockLayout;
use Semitexa\Cms\Domain\Model\ContentBlock;

/**
 * A page as blocks: the author's page, and one hidden input under it.
 *
 * ONLY THE HIDDEN INPUT CARRIES A NAME. Everything an author touches here — the
 * passage editors, the pickers, the alt fields, the two layout selects per
 * block — is DOM the console owns, collected into that one value on submit by
 * content-blocks.js. A per-block input with a name would arrive in
 * submittedValues() as a field the module never declared, and the save
 * contract is array<string, string> keyed by DECLARED field names.
 *
 * Layout is a pair of selects and not a style control on purpose: the choices
 * are named, the skin renders them, and the author is choosing where a thing
 * sits rather than what colour it is. Colour and font are brand, answered by
 * the line the editor page prints under the writing surface.
 *
 * A page written before this format decodes to one text block, so an author
 * opening such a page sees exactly what they wrote, in one passage, and splits
 * it whenever they like. Nothing is converted behind their back.
 *
 * The PICKER arrives as a callable rather than being built here: how an image
 * control looks belongs to the editor page, which uses the same one for a cover
 * field. Two copies of that markup would drift, and the one that drifted would
 * be the one nobody was looking at.
 */
final class ContentBlocksControl
{
    /** @param callable(string): string $picker renders the image control for an asset id */
    public function render(string $name, string $value, string $required, int $position, callable $picker): string
    {
        $escapedName = $this->escape($name);
        $id = 'blocks-' . $position . '-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $escapedName);

        $blocks = '';
        foreach ((new ContentBlockCodec())->decode($value) as $index => $block) {
            $blocks .= $this->blockEditor($id, $index, $block, $picker);
        }

        return '<input id="' . $id . '" type="hidden" name="' . $escapedName . '"'
            . ' value="' . $this->escape($value) . '"' . $required . '>'
            . '<div class="blocks" data-blocks="' . $id . '">' . $blocks . '</div>'
            . $this->templates($id, $picker)
            . '<div class="blocks__add">'
            . '<button type="button" class="ghost" data-block-add="text">+ Текст</button>'
            . '<button type="button" class="ghost" data-block-add="image">+ Зображення</button>'
            . '</div>';
    }

    /**
     * An empty block of each kind, for the client to clone.
     *
     * Because the alternative does not work, and it took a review pass to see
     * it: cloning an EXISTING block means an empty page can never gain its
     * first one, and a page holding only text can never gain a picture. Both
     * buttons simply do nothing, which reads as a broken console.
     *
     * A <template> is inert until inserted, so the editor inside it does not
     * upgrade, does not bind, and costs nothing until an author asks for it.
     *
     * @param callable(string): string $picker
     */
    private function templates(string $fieldId, callable $picker): string
    {
        $out = '';

        foreach ([ContentBlock::text(''), ContentBlock::image('')] as $blank) {
            $out .= '<template data-block-template="' . $blank->kind . '">'
                . $this->blockEditor($fieldId, -1, $blank, $picker)
                . '</template>';
        }

        return $out;
    }

    /**
     * One block: what it holds, where it sits, and the three things you can do
     * to it.
     *
     * @param callable(string): string $picker
     */
    private function blockEditor(string $fieldId, int $index, ContentBlock $block, callable $picker): string
    {
        $blockId = $fieldId . '-b' . $index;

        $body = $block->isText()
            ? '<input id="' . $blockId . '-v" type="hidden" value="' . $this->escape($block->payload) . '">'
                . '<trix-editor input="' . $blockId . '-v" class="rich"></trix-editor>'
            : $picker($block->payload)
                . '<label class="block__alt"><span>Опис для тих, хто не бачить зображення</span>'
                . '<input type="text" data-block-alt value="' . $this->escape($block->alt) . '"></label>';

        return '<article class="block" data-block data-block-kind="' . $this->escape($block->kind) . '">'
            . '<div class="block__body">' . $body . '</div>'
            . '<div class="block__bar">'
            . $this->layoutSelect('data-block-align', 'Розташування', BlockLayout::ALIGNMENTS, $block->layout->align, [
                'left' => 'Ліворуч', 'center' => 'По центру', 'right' => 'Праворуч',
            ])
            . $this->layoutSelect('data-block-size', 'Розмір', BlockLayout::SIZES, $block->layout->size, [
                'small' => 'Малий', 'medium' => 'Середній', 'full' => 'На всю ширину',
            ])
            . '<span class="block__acts">'
            . '<button type="button" class="ghost" data-block-move="up" aria-label="Вище">↑</button>'
            . '<button type="button" class="ghost" data-block-move="down" aria-label="Нижче">↓</button>'
            . '<button type="button" class="ghost" data-block-remove aria-label="Прибрати блок">×</button>'
            . '</span></div></article>';
    }

    /**
     * @param list<string> $values
     * @param array<string, string> $labels
     */
    private function layoutSelect(string $attribute, string $label, array $values, string $current, array $labels): string
    {
        $options = '';
        foreach ($values as $value) {
            $options .= '<option value="' . $value . '"' . ($value === $current ? ' selected' : '') . '>'
                . $this->escape($labels[$value] ?? $value) . '</option>';
        }

        return '<label class="block__choice"><span>' . $this->escape($label) . '</span>'
            . '<select ' . $attribute . '>' . $options . '</select></label>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
