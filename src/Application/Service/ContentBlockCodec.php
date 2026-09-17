<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\BlockLayout;
use Semitexa\Cms\Domain\Model\ContentBlock;

/**
 * How a page's blocks become one string, and back.
 *
 * The CMS owns the page format; a module stores the string and never parses it.
 * That is not a new bargain — {@see \Semitexa\Cms\Domain\Model\ContentField::IMAGE}
 * already stores a media asset id the module does not interpret, and the
 * refusal of "a JSON blob in a textarea" in that same docblock was about
 * handing an AUTHOR raw JSON, not about the CMS owning a serialisation. So
 * save(array<string, string>) is untouched and no module changes.
 *
 * DECODING NEVER THROWS, and that is the load-bearing rule. The value comes out
 * of somebody's table: it may predate this format, it may have been written by
 * a chat skill, it may be half a string. A page that refuses to open because
 * its body will not parse has turned a formatting problem into a lost page —
 * so anything unreadable becomes ONE TEXT BLOCK holding what was there, which
 * is exactly what every page written before this format is.
 *
 * That migration is therefore not a step anybody runs. It is what decode() does
 * to an old value the first time a page is opened, and re-encoding writes the
 * new shape back on the first save.
 */
final class ContentBlockCodec
{
    /** Marks a value as this format. A value without it is content, not a document. */
    private const MARKER = 'semitexa.cms.blocks/v1';

    /** @param list<ContentBlock> $blocks */
    public function encode(array $blocks): string
    {
        // An empty page is the EMPTY STRING, which is what decode() reads back
        // as an empty page. Encoding it as a marked document with no blocks
        // made the pair asymmetric: decode() refuses an empty `blocks` list and
        // falls back to one text block, so a stored `encode([])` came back as
        // a page whose words were its own JSON.
        if ($blocks === []) {
            return '';
        }

        $payload = [
            'format' => self::MARKER,
            'blocks' => array_map(static fn (ContentBlock $b): array => $b->toArray(), array_values($blocks)),
        ];

        try {
            return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Encoding cannot be allowed to lose a page either. Falling back to
            // the text of the blocks keeps the author's words, which decode()
            // will read back as one block — degraded, never empty.
            return implode("\n", array_map(static fn (ContentBlock $b): string => $b->payload, $blocks));
        }
    }

    /** @return list<ContentBlock> */
    public function decode(string $value): array
    {
        // TRIMMED decides; the ORIGINAL is what gets kept. The promise this
        // codec makes is that a page written before the format comes back byte
        // for byte, and handing the trimmed copy to asOneBlock() quietly ate
        // the leading and trailing whitespace of every legacy value — a
        // difference the author never made, written back on the next save.
        // Whitespace-only input is the exception, and it is an empty page.
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        if (!str_contains($trimmed, self::MARKER)) {
            return $this->asOneBlock($value);
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = (array) json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->asOneBlock($value);
        }

        if (($decoded['format'] ?? null) !== self::MARKER || !is_array($decoded['blocks'] ?? null)) {
            return $this->asOneBlock($value);
        }

        $blocks = [];
        foreach ($decoded['blocks'] as $raw) {
            $block = $this->blockFrom($raw);

            // ONE unreadable block is enough to keep the whole string. Taking
            // the readable ones and dropping the rest looks tidier and is how
            // a page loses a paragraph: the author opens it, sees what
            // survived, saves for some unrelated reason, and the dropped block
            // is gone from storage for good. Preserving the value is the
            // promise this codec makes, and a partial read does not keep it.
            if ($block === null) {
                return $this->asOneBlock($value);
            }

            $blocks[] = $block;
        }

        // A value that claimed to be ours and carried no blocks at all: keep
        // the string rather than open an empty page.
        return $blocks === [] ? $this->asOneBlock($value) : $blocks;
    }

    /**
     * A page written before this format existed, in the shape it will keep.
     *
     * @return list<ContentBlock>
     */
    private function asOneBlock(string $value): array
    {
        return [ContentBlock::text($value)];
    }

    private function blockFrom(mixed $raw): ?ContentBlock
    {
        if (!is_array($raw)) {
            return null;
        }

        // A MISSING payload is not an empty one. Read as '', the block came
        // back as a blank passage and the next save wrote that blankness over
        // whatever the document actually held.
        if (!array_key_exists('payload', $raw) || !is_string($raw['payload'])) {
            return null;
        }

        $payload = $raw['payload'];

        $layout = is_array($raw['layout'] ?? null) ? $raw['layout'] : [];
        $align = is_string($layout['align'] ?? null) ? $layout['align'] : null;
        $size = is_string($layout['size'] ?? null) ? $layout['size'] : null;

        // Present but not a string is a document this cannot read, not a
        // description to quietly drop.
        if (array_key_exists('alt', $raw) && !is_string($raw['alt'])) {
            return null;
        }

        $alt = (string) ($raw['alt'] ?? '');

        // Same rule as `alt` above, for the field that decides what the block
        // IS. A present `kind` that is not a string failed the comparison
        // below and became a text block — and the next save wrote that back,
        // replacing whatever the document actually said with `"kind":"text"`.
        // A kind this cannot read means the document cannot be read.
        if (array_key_exists('kind', $raw) && !is_string($raw['kind'])) {
            return null;
        }

        return ($raw['kind'] ?? ContentBlock::TEXT) === ContentBlock::IMAGE
            ? ContentBlock::image($payload, $alt, BlockLayout::of($align, $size))
            : ContentBlock::text($payload, BlockLayout::of($align, $size));
    }
}
