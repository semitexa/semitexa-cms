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
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        if (!str_contains($trimmed, self::MARKER)) {
            return $this->asOneBlock($trimmed);
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = (array) json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->asOneBlock($trimmed);
        }

        if (($decoded['format'] ?? null) !== self::MARKER || !is_array($decoded['blocks'] ?? null)) {
            return $this->asOneBlock($trimmed);
        }

        $blocks = [];
        foreach ($decoded['blocks'] as $raw) {
            $block = $this->blockFrom($raw);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        // Every block unreadable, and a value that claimed to be ours: keep the
        // string rather than open an empty page.
        return $blocks === [] ? $this->asOneBlock($trimmed) : $blocks;
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

        $payload = $raw['payload'] ?? '';
        if (!is_string($payload)) {
            return null;
        }

        $layout = is_array($raw['layout'] ?? null) ? $raw['layout'] : [];
        $align = is_string($layout['align'] ?? null) ? $layout['align'] : null;
        $size = is_string($layout['size'] ?? null) ? $layout['size'] : null;

        $alt = is_string($raw['alt'] ?? null) ? $raw['alt'] : '';

        return ($raw['kind'] ?? ContentBlock::TEXT) === ContentBlock::IMAGE
            ? ContentBlock::image($payload, $alt, BlockLayout::of($align, $size))
            : ContentBlock::text($payload, BlockLayout::of($align, $size));
    }
}
