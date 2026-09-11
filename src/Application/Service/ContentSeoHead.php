<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\ContentSeo;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Ssr\Application\Service\Seo\SeoMeta;

/**
 * Where the metadata the CMS holds becomes metadata a reader's browser sees.
 *
 * Everything upstream of this class — the meta layer, the writer, the debounce
 * — produced a row in a table. A description that never reaches a `<head>` is
 * decoration, and until this existed that is all any of it was.
 *
 * The CMS does not render the site; the application does. So this is a seam,
 * not a renderer: the application says which record a page is showing, and this
 * answers with what the CMS knows about it.
 *
 * **Two channels, because there really are two.** Most of the meta goes into
 * {@see SeoMeta}, which is where the framework's own head partials read from —
 * a theme already prints `meta('description')` and `meta('og:title')`, so
 * feeding that store makes the tags appear with no template change at all. The
 * canonical link and the JSON-LD block have no home there, so this prints them
 * itself. Mixing the two would emit every tag twice.
 *
 * Hence the pair, shaped exactly like `asset_require()` / `asset_head()`, which
 * the codebase already teaches:
 *
 *     {{ cms_seo(ref) }}       {# a statement: fills the request's meta #}
 *     {{ cms_seo_head() }}     {# prints what the meta store cannot carry #}
 *
 * A handler may call {@see apply()} directly instead, and should when it can:
 * the document `<title>` is usually rendered before any template gets a chance
 * to run this, and only a handler runs early enough to influence it.
 *
 * **Never overwrites.** Every value goes in as a DEFAULT. A page that set its
 * own description keeps it — the CMS fills what nobody filled, which is the
 * same promise the meta layer makes about an author's own text one level up.
 */
#[AsService]
final class ContentSeoHead
{
    /** Where {@see apply()} leaves the record for {@see extraTags()} to print. */
    private const APPLIED = 'cms.seo.applied';

    #[InjectAsReadonly]
    protected SeoStore $store;

    /**
     * Fill this request's metadata from what the CMS holds for `$ref`.
     *
     * Returns the record it applied so a caller that wants to render something
     * of its own — a title, a share card — does not have to read it twice.
     */
    public function apply(string $ref): ContentSeo
    {
        $seo = $this->store->get($ref);

        // The title is set rather than defaulted, but only into an empty slot:
        // SeoMeta has no setDefault for it, and a page that already named
        // itself has said something more specific than a generator could.
        if ($seo->title !== '' && SeoMeta::getTitle() === '') {
            SeoMeta::setTitle($seo->title);
        }

        SeoMeta::setDefault('description', $seo->description);
        SeoMeta::setDefault('og:title', $seo->ogTitle !== '' ? $seo->ogTitle : $seo->title);
        SeoMeta::setDefault('og:description', $seo->ogDescription !== '' ? $seo->ogDescription : $seo->description);
        SeoMeta::setDefault('og:image', $seo->ogImage);
        // Indexing policy, never generated — see ContentSeo. It reaches the
        // page only because someone decided it.
        SeoMeta::setDefault('robots', $seo->robots);

        CoroutineLocal::set(self::APPLIED, $seo);

        return $seo;
    }

    /**
     * The tags {@see SeoMeta} cannot carry, for the record {@see apply()} read.
     *
     * Empty when nothing was applied — a layout that prints this on every page
     * must be silent on the pages the CMS knows nothing about, not emit an
     * empty canonical pointing at nowhere.
     */
    public function extraTags(): string
    {
        $seo = CoroutineLocal::get(self::APPLIED);

        if (!$seo instanceof ContentSeo) {
            return '';
        }

        $html = '';

        if ($seo->canonical !== '') {
            $html .= '<link rel="canonical" href="' . self::escape($seo->canonical) . '">';
        }

        // Re-encoded rather than printed: the generated half was validated as
        // parseable on the way in, but the AUTHORED half is whatever a person
        // typed into the field, and either way this prints into a page where a
        // `<` inside a string value would close the element early. The element
        // itself is emitted only when something survives — an empty
        // `<script type="application/ld+json"></script>` is a parse error a
        // crawler reports, which is strictly worse than no structured data.
        $graph = self::safeJson($seo->jsonLd);

        if ($graph !== '') {
            $html .= '<script type="application/ld+json">' . $graph . '</script>';
        }

        return $html;
    }

    /**
     * Re-encode a stored graph so it cannot break out of a `<script>`.
     *
     * Anything that does not parse is dropped rather than printed: structured
     * data a crawler cannot read is a liability on the page, where absence
     * costs nothing.
     */
    private static function safeJson(string $jsonLd): string
    {
        $decoded = json_decode($jsonLd, true);

        if (!is_array($decoded) || $decoded === []) {
            return '';
        }

        return (string) json_encode(
            $decoded,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
