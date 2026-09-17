<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\ContentBlock;

/**
 * A page's blocks, as the markup a visitor gets.
 *
 * The layout an author chose arrives here as NAMES — center, medium — and
 * leaves as data attributes for the skin to style. That is the whole reason
 * layout lives on the block: the skin decides what "center" looks like, a skin
 * swap keeps it meaning the same thing, and nothing about appearance is stored
 * with the words.
 *
 * A DEFAULT BLOCK RENDERS AS ITS PAYLOAD AND NOTHING ELSE, and that property is
 * load-bearing rather than tidy: every page written before this format decodes
 * to one default text block, so a migrated page renders byte-for-byte what it
 * rendered before. A migration nobody can see is the only kind worth running on
 * somebody's live site.
 *
 * An image is rendered from its asset ID through the console's media route,
 * never from a stored URL — {@see \Semitexa\Cms\Domain\Model\ContentField::IMAGE}
 * states why: a URL beside the id is a second copy of a fact the media service
 * owns, and moving the storage would invalidate every page that cached one.
 */
final class ContentBlockRenderer
{
    private const MEDIA_ROUTE = '/os/app/cms/media/';

    /** @param list<ContentBlock> $blocks */
    public function render(array $blocks): string
    {
        $html = '';

        foreach ($blocks as $block) {
            // An empty passage is a gap nobody meant. Dropping it here rather
            // than refusing to save it keeps the editor forgiving and the page
            // clean.
            if ($block->isEmpty() && $block->isText()) {
                continue;
            }

            $html .= $block->isText() ? $this->text($block) : $this->image($block);
        }

        return $html;
    }

    private function text(ContentBlock $block): string
    {
        // Byte-identical to the old single-field page when nothing was chosen.
        return $block->layout->isDefault()
            ? $block->payload
            : '<div' . $block->layout->attributes() . '>' . $block->payload . '</div>';
    }

    private function image(ContentBlock $block): string
    {
        if ($block->payload === '') {
            return '';
        }

        // alt is always emitted, empty included: an empty alt says "decorative"
        // to a screen reader, while a missing one says "guess", and the guess is
        // the file name.
        return '<figure' . $block->layout->attributes() . '>'
            . '<img src="' . self::MEDIA_ROUTE . rawurlencode($block->payload) . '"'
            . ' alt="' . htmlspecialchars($block->alt, ENT_QUOTES, 'UTF-8') . '">'
            . '</figure>';
    }
}
