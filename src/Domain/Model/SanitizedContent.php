<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * What a submission looks like after the allowlist, and what the allowlist
 * would not let through.
 *
 * The values alone were the whole answer for as long as removal was assumed to
 * be harmless, and for most of what the allowlist drops it is: an unknown tag
 * loses its tag and keeps its text, an attribute we do not store loses a
 * width. An image is the exception — there is no text under it, so dropping it
 * removes something the author put there and leaves nothing in its place.
 *
 * So the refused image addresses travel back with the values. Not as a count:
 * an author told «одне зображення не збережено» still has to find out WHICH,
 * and the address is the only thing that identifies a picture they can no
 * longer see.
 */
final readonly class SanitizedContent
{
    /**
     * @param array<string, string> $values              ready to hand to the module
     * @param list<string>          $refusedImageSources in document order, deduplicated
     */
    public function __construct(
        public array $values,
        public array $refusedImageSources = [],
    ) {
    }

    /**
     * What to tell the author, or null when nothing was taken away.
     *
     * Says the save happened, because it did — refusing the whole write over a
     * picture we were never going to store would cost the author the text they
     * came to change. Then it names the addresses, because «зображення не
     * збережено» without them is a message nobody can act on.
     */
    public function notice(): ?string
    {
        if ($this->refusedImageSources === []) {
            return null;
        }

        $shown = array_slice($this->refusedImageSources, 0, 3);
        $rest = count($this->refusedImageSources) - count($shown);

        return sprintf(
            'Збережено, але зображення не з цього сайту не зберігаються — %d шт.: %s%s. '
            . 'Завантажте їх у текст через редактор, щоб вони лишились.',
            count($this->refusedImageSources),
            implode(', ', array_map(self::shorten(...), $shown)),
            $rest > 0 ? sprintf(' та ще %d', $rest) : '',
        );
    }

    /**
     * A URL long enough to recognise and short enough not to push the message
     * off the bar. The tail is what identifies a picture, so the middle goes.
     */
    private static function shorten(string $url): string
    {
        $max = 60;

        if (mb_strlen($url) <= $max) {
            return $url;
        }

        return mb_substr($url, 0, 30) . '…' . mb_substr($url, -25);
    }
}
