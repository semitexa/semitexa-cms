<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Core\Attribute\AsService;
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
 * An image's `src` is checked against this application's own media route and
 * nothing else. That is the whole point of storing an identifier URL rather
 * than a storage URL: the allowlist becomes a shape we own and can state
 * exactly, instead of "some host we hope is ours". An `<img>` pointing anywhere
 * else — a tracking pixel, an exfiltrating request, a picture that disappears
 * when someone else's server does — is not something an article may carry.
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
     * The only shape an image in stored markup may point at: our own media
     * route, addressed by asset id.
     *
     * Anchored at both ends on purpose — a prefix match would accept
     * `/os/app/cms/media/../../something`, and a suffix match would accept
     * `https://evil.test/os/app/cms/media/x`.
     */
    private const IMAGE_SRC = '#^/os/app/cms/media/[A-Za-z0-9._~-]+$#';

    private ?HtmlSanitizer $sanitizer = null;

    /**
     * Sanitise the values of fields the editor declared as HTML.
     *
     * Only those: a LINE or TEXT field is plain text, and running markup rules
     * over it would eat a legitimate `<` an author typed.
     *
     * @param array<string, string> $values     submitted, keyed by field name
     * @param list<string>          $htmlFields names of fields whose kind is HTML
     *
     * @return array<string, string>
     */
    public function sanitizeValues(array $values, array $htmlFields): array
    {
        foreach ($htmlFields as $name) {
            if (array_key_exists($name, $values)) {
                $values[$name] = $this->sanitize($values[$name]);
            }
        }

        return $values;
    }

    public function sanitize(string $html): string
    {
        return $this->dropForeignImages($this->sanitizer()->sanitize($html));
    }

    /**
     * Remove any <img> whose src is not our own media route.
     *
     * The sanitizer allowlists elements and attributes; it does not know what a
     * legitimate src looks like HERE. Done after sanitising, so the markup is
     * already reduced to the allowlist and the only attribute left to read is
     * one this class put there.
     */
    private function dropForeignImages(string $html): string
    {
        if (!str_contains($html, '<img')) {
            return $html;
        }

        return (string) preg_replace_callback(
            '#<img\b[^>]*>#i',
            static function (array $match): string {
                if (preg_match('#\ssrc="([^"]*)"#i', $match[0], $src) !== 1) {
                    return '';
                }

                $value = html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return preg_match(self::IMAGE_SRC, $value) === 1 ? $match[0] : '';
            },
            $html,
        );
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
            // An image may be relative and nothing else. No media schemes are
            // allowed at all, so an absolute src never survives this config —
            // the path check below is then a second gate on the shape, not the
            // only thing standing between an article and a foreign host.
            ->allowRelativeMedias()
            ->allowMediaSchemes([]);

        return $this->sanitizer = new HtmlSanitizer($config);
    }
}
