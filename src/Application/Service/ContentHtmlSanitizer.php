<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\SanitizedContent;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * What a rich field is allowed to store.
 *
 * The editor is not a security boundary — the browser posts a form, and a form
 * can carry anything whatever the widget on the page allows. So the allowlist
 * lives here, on the way in, and it is deliberately EQUAL to what the vendored
 * editor can actually produce rather than to "markup that looks harmless":
 *
 *   blocks  div, blockquote, h1, pre, ul, ol, li   (Trix block attributes)
 *   text    strong, em, del, a, br                 (Trix text attributes)
 *
 *   images  figure, figcaption, img            (Trix attachments)
 *
 * An image's `src` is checked against the addresses this installation actually
 * serves — {@see ContentImageSources} states them, and it is the only thing
 * here that knows about storage or tenants. An `<img>` pointing anywhere else
 * — a tracking pixel, an exfiltrating request, a picture that disappears when
 * someone else's server does — is not something an article may carry.
 *
 * Attributes: `href` on a link, `language` on a code block (the one HTML
 * attribute Trix declares for `pre`), and `src`/`alt`/`width`/`height` on an
 * image. No class, no style, no id, no `on*`.
 *
 * Link schemes are allowlisted, which is the check people skip: `javascript:`
 * and `data:` in an href are the classic way a "safe" rich text field becomes
 * an execution surface.
 */
#[AsService]
final class ContentHtmlSanitizer
{
    /** Schemes a stored link may use. */
    private const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * The largest document a rich field may store, in bytes.
     *
     * HtmlSanitizer defends itself against pathological input by TRUNCATING at
     * `maxInputLength` — 20 000 bytes by default — and saying nothing. An
     * author writing a long article would have been told «Збережено.» while the
     * tail of their text was cut off mid-tag, and only found out by reopening
     * it. So the library's silent cut is turned off and a limit of our own is
     * enforced here, where going over is an answer the author can read.
     *
     * 200 KB is a very long article: images are stored as references, not as
     * data, so the markup carries roughly a paragraph per 400 bytes.
     */
    private const MAX_BYTES = 200_000;

    private ?HtmlSanitizer $sanitizer = null;

    /**
     * Which image addresses survive. Lazily defaulted rather than required,
     * so this class still stands up under a bare `new` — the default answers
     * for the identifier route alone, which is the safe direction to fail.
     */
    #[InjectAsReadonly]
    protected ContentImageSources $sources;

    /**
     * Sanitise the values of fields the editor declared as HTML, and say what
     * that cost.
     *
     * Only those fields: a LINE or TEXT field is plain text, and running markup
     * rules over it would eat a legitimate `<` an author typed.
     *
     * The result carries the image addresses the allowlist would not take,
     * because dropping one of those is the only removal here that loses
     * something an author would miss — everything else the allowlist strips
     * leaves its text behind. A save that quietly emptied an article of its
     * pictures under «Збережено.» is the failure this reports on.
     *
     * @param array<string, string> $values     submitted, keyed by field name
     * @param list<string>          $htmlFields names of fields whose kind is HTML
     */
    public function sanitizeValues(array $values, array $htmlFields): SanitizedContent
    {
        $refused = [];

        foreach ($htmlFields as $name) {
            if (array_key_exists($name, $values)) {
                $values[$name] = $this->clean($values[$name], $refused);
            }
        }

        return new SanitizedContent($values, array_values(array_unique($refused)));
    }

    /**
     * The markup alone, for a caller with one document and no interest in what
     * was refused.
     *
     * @throws \InvalidArgumentException when the document is over {@see self::MAX_BYTES}
     */
    public function sanitize(string $html): string
    {
        $refused = [];

        return $this->clean($html, $refused);
    }

    /**
     * @param list<string> $refused image addresses this pass would not keep, appended to
     *
     * @throws \InvalidArgumentException when the document is over {@see self::MAX_BYTES}
     */
    private function clean(string $html, array &$refused): string
    {
        if (strlen($html) > self::MAX_BYTES) {
            // Refused whole rather than stored in part: half an article saved
            // under a success message is worse than a save that did not happen.
            throw new \InvalidArgumentException(sprintf(
                'Текст завеликий: %d КБ, а можна щонайбільше %d КБ. Розділіть його на кілька записів.',
                (int) ceil(strlen($html) / 1024),
                (int) (self::MAX_BYTES / 1024),
            ));
        }

        // Read BEFORE sanitising. The allowlist permits only http and https as
        // media schemes, so Symfony strips the `src` of a `data:` image outright
        // — and what reaches dropForeignImages() is then an <img> with no src,
        // which that method deliberately passes over in silence. A pasted
        // screenshot therefore vanished under «Збережено.» with nothing said,
        // which is the exact failure this reporting exists to prevent.
        $this->noteRefusedSources($html, $refused);

        return $this->dropEmptyFigures($this->dropForeignImages($this->sanitizer()->sanitize($html), $refused));
    }

    /**
     * Name the image addresses this pass will not keep, from the markup as
     * SUBMITTED.
     *
     * Asked of the raw document because the sanitizer answers part of the
     * question destructively: a scheme it refuses is not rejected as an image,
     * it is erased as an attribute, and an attribute that is gone cannot be
     * reported. Whether a picture was dropped by the scheme allowlist or by our
     * own ownership test is of no interest to the author — what they need is the
     * address, so they can see which picture is about to disappear.
     *
     * Duplicates are harmless: {@see sanitizeValues()} reduces the list once at
     * the end, so an address refused here and again downstream is named once.
     *
     * @param list<string> $refused appended to
     */
    private function noteRefusedSources(string $html, array &$refused): void
    {
        if (!str_contains($html, '<img')) {
            return;
        }

        if (preg_match_all('#<img\b[^>]*\ssrc="([^"]*)"#i', $html, $matches) === 0) {
            return;
        }

        $sources = $this->sources();

        foreach ($matches[1] as $raw) {
            $value = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($value !== '' && !$sources->allows($value)) {
                $refused[] = $value;
            }
        }
    }

    /**
     * Remove a <figure> left holding nothing.
     *
     * Dropping a foreign image leaves its wrapper behind, and an empty figure
     * still takes vertical space in a rendered article — so the reader sees a
     * gap where a picture was refused, and the author sees a hole they cannot
     * select or delete. Removing the image should remove the place it sat.
     */
    private function dropEmptyFigures(string $html): string
    {
        if (!str_contains($html, '<figure')) {
            return $html;
        }

        return (string) preg_replace('#<figure\b[^>]*>\s*</figure>#i', '', $html);
    }

    /**
     * Remove any <img> whose src is not our own media route.
     *
     * The sanitizer allowlists elements and attributes; it does not know what a
     * legitimate src looks like HERE. Done after sanitising, so the markup is
     * already reduced to the allowlist and the only attribute left to read is
     * one this class put there.
     */
    /**
     * @param list<string> $refused addresses of the images dropped here, appended to
     */
    private function dropForeignImages(string $html, array &$refused): string
    {
        if (!str_contains($html, '<img')) {
            return $html;
        }

        $sources = $this->sources();

        return (string) preg_replace_callback(
            '#<img\b[^>]*>#i',
            static function (array $match) use ($sources, &$refused): string {
                // No src is not an image. Nothing is shown and nothing is lost,
                // so it goes without being reported — a warning about markup
                // that displayed nothing would train authors to ignore the
                // warnings that matter.
                if (preg_match('#\ssrc="([^"]*)"#i', $match[0], $src) !== 1) {
                    return '';
                }

                $value = html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($sources->allows($value)) {
                    return $match[0];
                }

                $refused[] = $value;

                return '';
            },
            $html,
        );
    }

    private function sources(): ContentImageSources
    {
        return $this->sources ??= new ContentImageSources();
    }

    private function sanitizer(): HtmlSanitizer
    {
        if ($this->sanitizer !== null) {
            return $this->sanitizer;
        }

        $config = (new HtmlSanitizerConfig())
            ->allowElement('div')
            ->allowElement('br')
            ->allowElement('blockquote')
            ->allowElement('h1')
            ->allowElement('pre', ['language'])
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('del')
            ->allowElement('a', ['href'])
            ->allowElement('figure')
            ->allowElement('figcaption')
            ->allowElement('img', ['src', 'alt', 'width', 'height'])
            // Text inside a dropped element is kept — losing an author's
            // paragraph because it carried a stray tag would be its own kind of
            // data loss, and the text was never the dangerous part.
            ->allowRelativeLinks()
            ->allowLinkSchemes(self::LINK_SCHEMES)
            // An image may be relative, or it may name a host over http(s) —
            // a deployment whose media sits on a CDN publishes exactly that,
            // and refusing schemes outright meant such an installation could
            // never keep a picture of its own. Which hosts and paths are ours
            // is not a question this library can answer, so it is asked of
            // ContentImageSources afterwards; letting the scheme through here
            // makes that check the gate rather than a second opinion, and the
            // foreign-source cases in the test suite are what hold it shut.
            ->allowRelativeMedias()
            ->allowMediaSchemes(['http', 'https'])
            // Never truncate. The library's default cuts at 20 000 bytes and
            // returns the prefix as if it were the whole document; the size
            // rule is enforced in sanitize(), where it can be reported.
            ->withMaxInputLength(-1);

        return $this->sanitizer = new HtmlSanitizer($config);
    }
}
