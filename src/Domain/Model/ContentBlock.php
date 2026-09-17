<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * One piece of a page: what it is, what it holds, and where it sits.
 *
 * A page stopped being one rich-text string because layout could not live
 * there. Measured against the vendored editor: Trix 2.1.19 parses a block
 * attribute value and serialises it away, so an author who opened an aligned
 * page and saved it lost the alignment silently. Here the layout is a property
 * of the BLOCK, which the CMS owns — no editor can eat it, because no editor
 * is ever handed it.
 *
 * The payload stays a string in both kinds, and deliberately: a TEXT block
 * holds the sanitised HTML of one passage, an IMAGE block holds a media asset
 * id. That is the same bargain {@see ContentField::IMAGE} already makes — the
 * module stores an opaque string whose meaning the CMS keeps.
 */
final readonly class ContentBlock
{
    public const TEXT = 'text';
    public const IMAGE = 'image';

    private function __construct(
        public string $kind,
        public string $payload,
        public BlockLayout $layout,
        /**
         * What the picture says to someone who cannot see it.
         *
         * On the block and not in the payload, because the payload of an image
         * block is an asset id and alt text is not part of an asset — the same
         * picture carries a different description on a different page. Empty is
         * a legitimate value and means decorative; it is not the same as
         * missing, and the renderer emits alt="" for it deliberately rather
         * than leaving the attribute off.
         */
        public string $alt = '',
    ) {}

    public static function text(string $html, ?BlockLayout $layout = null): self
    {
        return new self(self::TEXT, $html, $layout ?? BlockLayout::default());
    }

    /** @param string $assetId a media asset id, never a URL — the same rule ContentField::IMAGE states */
    public static function image(string $assetId, string $alt = '', ?BlockLayout $layout = null): self
    {
        return new self(self::IMAGE, trim($assetId), $layout ?? BlockLayout::default(), $alt);
    }

    public function isText(): bool
    {
        return $this->kind === self::TEXT;
    }

    /** A block with nothing in it is not a block: an empty passage renders as a gap nobody meant. */
    public function isEmpty(): bool
    {
        // stripos: HTML tag names are case-insensitive, and an image-only
        // block written with <IMG> read as empty — the renderer then dropped
        // the whole block, picture and all.
        //
        // A non-breaking space is whitespace to a reader and not to trim(),
        // so `<div>&nbsp;</div>` — which is what an editor leaves behind when
        // the author clears a paragraph — counted as content. A REQUIRED
        // field then passed its check and saved a page with nothing visible
        // on it. Both spellings are normalised: the entity as stored, and the
        // character the browser sends back.
        $text = str_replace(["\u{00A0}", '&nbsp;', '&#160;', '&#xA0;'], ' ', strip_tags($this->payload));

        return trim($text) === '' && stripos($this->payload, '<img') === false;
    }

    public function withLayout(BlockLayout $layout): self
    {
        return new self($this->kind, $this->payload, $layout, $this->alt);
    }

    /** @return array{kind: string, payload: string, layout: array{align: string, size: string}, alt?: string} */
    public function toArray(): array
    {
        $out = ['kind' => $this->kind, 'payload' => $this->payload, 'layout' => $this->layout->toArray()];

        // Only when it says something: an empty alt on every text block would
        // be noise in every stored page.
        if ($this->alt !== '') {
            $out['alt'] = $this->alt;
        }

        return $out;
    }
}
