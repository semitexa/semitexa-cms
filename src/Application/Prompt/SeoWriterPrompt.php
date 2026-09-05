<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Prompt;

use Semitexa\Cms\Domain\Model\ContentSeo;
use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * The prompt that writes a page's metadata from the page's own content.
 *
 * A real prompt rather than a nowdoc inside the service, so an operator can
 * read it with `prompt:show --id=cms.seo.write` and override it per tenant.
 * That is not a nicety here: house style, tone and the site's name are exactly
 * what differs between two sites sharing one install.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'cms',
    template: 'resources/prompts/cms.seo.write.twig',
    description: 'Writes a page\'s search and social metadata from its own content.',
)]
final class SeoWriterPrompt implements BoundPromptInterface
{
    public const ID = 'cms.seo.write';

    /**
     * @param list<string> $requested the fields still open to generation
     */
    public function __construct(
        private readonly string $pageTitle = '',
        private readonly string $body = '',
        private readonly string $language = 'the language of the content',
        private readonly string $siteName = '',
        private readonly string $publicUrl = '',
        private readonly array $requested = ContentSeo::GENERATED_FIELDS,
    ) {}

    /**
     * @param list<string> $requested
     */
    public function withData(
        string $pageTitle,
        string $body,
        string $language,
        string $siteName,
        string $publicUrl,
        array $requested,
    ): self {
        return new self($pageTitle, $body, $language, $siteName, $publicUrl, $requested);
    }

    public function promptId(): string
    {
        return self::ID;
    }

    public function title(): string
    {
        return $this->pageTitle;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function siteName(): string
    {
        return $this->siteName;
    }

    public function publicUrl(): string
    {
        return $this->publicUrl;
    }

    /** @return list<string> */
    public function requested(): array
    {
        return $this->requested;
    }

    public function descriptionLimit(): int
    {
        return ContentSeo::DESCRIPTION_LIMIT;
    }

    public function titleLimit(): int
    {
        return ContentSeo::TITLE_LIMIT;
    }

    /**
     * The exact JSON skeleton to reply with — built from the requested fields,
     * so a page whose description someone already wrote never sees the key and
     * cannot be tempted to rewrite it.
     */
    public function shape(): string
    {
        $shape = [];
        foreach ($this->requested as $field) {
            $shape[] = '"' . $field . '":"…"';
        }

        return '{' . implode(',', $shape) . '}';
    }
}
