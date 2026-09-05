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
 * Everything else is dropped, including `figure`/`img`: Trix can make those for
 * file attachments, but nothing here wires an upload path, so accepting them
 * would mean storing whatever URL a client chose to send. When attachments are
 * built, this list is extended on purpose, together with them.
 *
 * Attributes: `href` on a link and `language` on a code block — the one HTML
 * attribute Trix declares for `pre`. No class, no style, no id, no data-*.
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
        return $this->sanitizer()->sanitize($html);
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
            // Text inside a dropped element is kept — losing an author's
            // paragraph because it carried a stray tag would be its own kind of
            // data loss, and the text was never the dangerous part.
            ->allowRelativeLinks()
            ->allowLinkSchemes(self::LINK_SCHEMES);

        return $this->sanitizer = new HtmlSanitizer($config);
    }
}
