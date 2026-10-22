<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service\Twig;

use Semitexa\Cms\Application\Service\ContentBlockCodec;
use Semitexa\Cms\Application\Service\ContentBlockRenderer;
use Semitexa\Cms\Application\Service\ContentHtmlSanitizer;
use Semitexa\Cms\Domain\Model\ContentBlock;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Ssr\Application\Service\Asset\AssetCollectorStore;
use Semitexa\Ssr\Application\Service\Extension\TwigExtensionRegistry;
use Semitexa\Ssr\Attribute\AsTwigExtension;
use Twig\Markup;

/**
 * A page written as blocks, printed in a site template:
 *
 *     {{ cms_blocks(page.body) }}
 *
 * The stored value is the block document the editor saves (or, for a page
 * written before blocks, its plain HTML — that renders as before, less what
 * the display sanitiser takes out).
 * Without this a template had nothing to call: printing the field gave the
 * visitor the stored JSON.
 *
 * Markup by design, like the field itself, and sanitised again as it is
 * printed (ContentBlockRenderer): the save is not the only way into a row. When a block carries a chosen position or size, the
 * default block stylesheet (`cms:css:content-blocks`) is required for the
 * page; a skin's own `.cms-block` rules override it.
 *
 * `#[AsService]` beside `#[AsTwigExtension]`: see ContentSeoTwigExtension.
 */
#[AsService]
#[AsTwigExtension]
final class ContentBlocksTwigExtension
{
    public const STYLESHEET = 'cms:css:content-blocks';

    /** Optional only for an extension built by hand in a test. */
    #[InjectAsReadonly(optional: true)]
    protected ContentHtmlSanitizer $sanitizer;

    public function registerFunctions(): void
    {
        TwigExtensionRegistry::registerFunction('cms_blocks', [$this, 'render'], ['is_safe' => ['html']]);
    }

    /** @param string|list<ContentBlock>|null $value the stored field, or blocks already decoded */
    public function render(string|array|null $value): Markup
    {
        $blocks = is_array($value) ? $value : (new ContentBlockCodec())->decode((string) $value);
        $html = (new ContentBlockRenderer(isset($this->sanitizer) ? $this->sanitizer : null))->render($blocks);
        if (str_contains($html, 'class="cms-block"')) {
            AssetCollectorStore::get()->require(self::STYLESHEET);
        }

        return new Markup($html, 'UTF-8');
    }
}
