<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service\Twig;

use Semitexa\Cms\Application\Service\ContentSeoHead;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Attribute\AsTwigExtension;
use Twig\Markup;

/**
 * The two head functions a site template calls to show what the CMS knows
 * about the page it is rendering.
 *
 * Deliberately the same pair as `asset_require()` / `asset_body()`: one is a
 * statement that registers something for this request, the other prints what
 * was registered. An author who has met one has met both.
 *
 *     {{ cms_seo(ref) }}       above the meta tags, or from the handler
 *     {{ cms_seo_head() }}     wherever the rest of the head is printed
 *
 * `cms_seo()` returns an empty string because it is a statement wearing a
 * function's clothes — the values it sets are read later, by the theme's own
 * `meta()` calls and by `cms_seo_head()`.
 *
 * ⚠️ `#[AsService]` alongside `#[AsTwigExtension]` is not decoration: the
 * catalog discovers by the second and BUILDS through the container, which only
 * knows classes carrying the first. Without it the extension is built with
 * `new`, no injection runs, and the failure is a Twig RuntimeError naming an
 * uninitialised property — at render time, on a page.
 */
#[AsService]
#[AsTwigExtension]
final class ContentSeoTwigExtension
{
    #[InjectAsReadonly]
    protected ContentSeoHead $head;

    public function registerFunctions(): void
    {
        TwigExtensionRegistry::registerFunction('cms_seo', [$this, 'applySeo']);
        TwigExtensionRegistry::registerFunction('cms_seo_head', [$this, 'renderHead'], ['is_safe' => ['html']]);
    }

    /** Fill this request's metadata for a record. Emits nothing itself. */
    public function applySeo(string $ref): string
    {
        $this->head->apply($ref);

        return '';
    }

    /** The canonical link and the JSON-LD block — markup by design. */
    public function renderHead(): Markup
    {
        return new Markup($this->head->extraTags(), 'UTF-8');
    }
}
