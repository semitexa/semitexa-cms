<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\ContentSeo;

/**
 * What the editor calls each metadata field, in the operator's language.
 *
 * Apart from {@see ContentEditorPage} only so the page stays within its
 * structural budget; the page is the one caller.
 */
final class ContentSeoLabels
{
    /**
     * Label and hint for each metadata field the editor owns, in the order an
     * author reads them. The KEYS are not declared here — they are
     * {@see ContentSeo::EDITOR_FIELDS}, which is also what the request accepts,
     * so the form and the gate cannot drift into disagreeing about which fields
     * an author may set.
     *
     * `jsonLd` is generated but deliberately absent: it is a graph for machines,
     * and a textarea of raw JSON in a content console is a way to store
     * something no crawler can parse. It regenerates freely and nobody has to
     * look at it.
     *
     * @return array{0: string, 1: string} [label, hint]
     */
    public static function of(string $field): array
    {
        return match ($field) {
            'title' => [CmsText::t('seo.title.label', [], 'Title in search results'), ''],
            'description' => [
                CmsText::t('seo.description.label', [], 'Description in search results'),
                CmsText::t('seo.description.hint', [], 'About 160 characters — Google cuts off the rest.'),
            ],
            'ogTitle' => [CmsText::t('seo.og_title.label', [], 'Title on social media'), ''],
            'ogDescription' => [CmsText::t('seo.og_description.label', [], 'Description on social media'), ''],
            'ogImage' => [
                CmsText::t('seo.og_image.label', [], 'Image for social media'),
                CmsText::t('seo.og_image.hint', [], 'Address of the image shown when the page is shared.'),
            ],
            'canonical' => [
                CmsText::t('seo.canonical.label', [], 'Canonical address'),
                CmsText::t('seo.canonical.hint', [], 'The main address of the page, if it has several.'),
            ],
            'robots' => [
                CmsText::t('seo.robots.label', [], 'Indexing'),
                CmsText::t('seo.robots.hint', [], 'For example index,follow or noindex.'),
            ],
            default => [$field, ''],
        };
    }
}
