<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * Where a block sits and how large it is — the two layout decisions an author
 * owns, as NAMED choices rather than as style.
 *
 * ASKED FOR BY A SITE'S OWN EDITOR, who looked for text alignment and a way to
 * change an illustration's size and found neither. They are layout decisions
 * about one piece of content, so an author owns them. Colour and font are
 * brand: the skin owns those, and the editor now says so.
 *
 * NAMED, not measured, and the difference is the whole design. A stored
 * `style="text-align:center"` is a colour picker away from an author restyling
 * a site one paragraph at a time; `center` is a decision the skin renders
 * however that skin wants, and a skin swap keeps it meaning the same thing. A
 * pixel width outlives the layout it was chosen in; `medium` does not.
 *
 * Unknown values fall back rather than throw: a block is content, and refusing
 * to show a page because someone stored `align: middle` would lose the text to
 * save the alignment.
 */
final readonly class BlockLayout
{
    public const ALIGN_LEFT = 'left';
    public const ALIGN_CENTER = 'center';
    public const ALIGN_RIGHT = 'right';

    public const SIZE_SMALL = 'small';
    public const SIZE_MEDIUM = 'medium';
    public const SIZE_FULL = 'full';

    /** @var list<string> */
    public const ALIGNMENTS = [self::ALIGN_LEFT, self::ALIGN_CENTER, self::ALIGN_RIGHT];

    /** @var list<string> */
    public const SIZES = [self::SIZE_SMALL, self::SIZE_MEDIUM, self::SIZE_FULL];

    private function __construct(
        public string $align,
        public string $size,
    ) {}

    /** What a block gets when nobody chose: text reads left, a picture fills the column. */
    public static function default(): self
    {
        return new self(self::ALIGN_LEFT, self::SIZE_FULL);
    }

    public static function of(?string $align, ?string $size): self
    {
        return new self(
            in_array($align, self::ALIGNMENTS, true) ? $align : self::ALIGN_LEFT,
            in_array($size, self::SIZES, true) ? $size : self::SIZE_FULL,
        );
    }

    public function isDefault(): bool
    {
        return $this->align === self::ALIGN_LEFT && $this->size === self::SIZE_FULL;
    }

    /**
     * What the renderer puts on the element, for the skin to style.
     *
     * Attributes rather than classes: a class is a name in the skin's own
     * namespace and collides with whatever else the page carries, while
     * `data-align` says what it is and can only be one of three things.
     */
    public function attributes(): string
    {
        return ' data-align="' . $this->align . '" data-size="' . $this->size . '"';
    }

    /** @return array{align: string, size: string} */
    public function toArray(): array
    {
        return ['align' => $this->align, 'size' => $this->size];
    }
}
