<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Application\Prompt\SeoWriterPrompt;
use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;
use Semitexa\Cms\Domain\Model\ContentSeo;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Llm\Application\Service\LlmProviderResolver;
use Semitexa\Llm\Application\Service\Planner;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Prompt\Application\Service\PromptRenderer;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;

/**
 * Writes a page's metadata from the page's own content.
 *
 * Unlike translation — which the CMS delegates, because only the module knows
 * its languages and where the overlay lives — metadata is derivable from the
 * {@see ContentDraft} the CMS already holds. So this is the one piece of
 * content work the CMS does itself, and the reason an app gets SEO without
 * writing any.
 *
 * Everything it produces is checked before it is kept: the model is asked for
 * exactly the fields still open to generation and nothing else survives, over-
 * long text is cut at a word boundary rather than mid-word, and structured data
 * that is not valid JSON is dropped. Broken JSON-LD on a page is worse than
 * none — a search engine flags it, where absence costs nothing.
 */
#[AsService]
final class SeoWriter
{
    /** How much of the page to show the model. Enough to describe, not to bore. */
    private const BODY_LIMIT = 6000;

    #[InjectAsReadonly]
    protected LlmProviderResolver $providers;

    #[InjectAsReadonly]
    protected PromptRepositoryInterface $prompts;

    private ?PromptRenderer $renderer = null;

    /**
     * Bring the generated half of a page's metadata up to date.
     *
     * Returns the record unchanged when there is nothing to do — every field
     * claimed by a person, or a model that would not answer. A failed call is
     * NOT an empty result: overwriting good metadata with nothing because a
     * provider blinked is the one outcome worth guarding against.
     *
     * @throws \RuntimeException when the work could not be done and is worth retrying
     */
    public function write(ContentSeo $seo, ContentDraft $draft, string $sourceHash, string $language = '', string $siteName = ''): ContentSeo
    {
        $requested = $seo->openToGeneration();
        if ($requested === []) {
            return $seo;
        }

        $body = $this->body($draft);
        if (trim($body) === '') {
            // An empty page has nothing to say about itself, and a model asked
            // to describe nothing will invent something.
            return $seo;
        }

        $prompt = (new SeoWriterPrompt())->withData(
            pageTitle: $draft->title,
            body: $body,
            language: $language !== '' ? $language : 'the language of the content',
            siteName: $siteName,
            publicUrl: $draft->publicUrl ?? '',
            requested: $requested,
        );

        $rendered = $this->renderer()->render($prompt, [], $this->prompts);

        $response = $this->providers->provider()->complete(new LlmRequest(
            systemPrompt: $rendered->system,
            userMessage: 'Write the metadata for this page.',
            history: [],
        ));

        if (!$response->success) {
            throw new \RuntimeException('The metadata writer could not reach the model: ' . ($response->error ?? 'unknown error'));
        }

        $decoded = (new Planner())->extractJson(trim($response->content));
        if ($decoded === null) {
            throw new \RuntimeException('The metadata writer returned something that was not JSON.');
        }

        return $seo->withGenerated($this->clean($decoded, $requested), $sourceHash);
    }

    /**
     * Keep only what was asked for, and only in a shape worth storing.
     *
     * @param array<string, mixed> $decoded raw reply; jsonLd may be an object or a string of one
     * @param list<string>         $requested
     *
     * @return array<string, string>
     */
    private function clean(array $decoded, array $requested): array
    {
        $out = [];

        foreach ($requested as $field) {
            $value = $decoded[$field] ?? null;

            if ($field === 'jsonLd') {
                // Accept the object as well as a string of it. Asking a model to
                // escape JSON inside JSON is fighting it: measured against a live
                // model, it returns the object inline every time, and requiring a
                // string silently dropped a perfectly good graph.
                //
                // Structured data that does not parse is a liability on the page —
                // a search engine flags it, where absence costs nothing — so
                // anything that is not an object is dropped rather than stored.
                $parsed = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : null);
                if (!is_array($parsed) || $parsed === []) {
                    continue;
                }
                $out[$field] = (string) json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                continue;
            }

            if (!is_string($value)) {
                continue;
            }
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));
            if ($value === '') {
                continue;
            }

            $limit = match ($field) {
                'description', 'ogDescription' => ContentSeo::DESCRIPTION_LIMIT,
                default => ContentSeo::TITLE_LIMIT,
            };
            $out[$field] = $this->clamp($value, $limit);
        }

        return $out;
    }

    /** Cut at the last word boundary that fits — a sentence ending mid-word reads as broken. */
    private function clamp(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        $cut = mb_substr($value, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > 0 ? mb_substr($cut, 0, $space) : $cut, " ,;:—-");
    }

    /** The page as the model should read it: labelled values, markup stripped. */
    private function body(ContentDraft $draft): string
    {
        $parts = [];
        foreach ($draft->fields as $field) {
            $value = trim($field->value);
            if ($value === '') {
                continue;
            }
            if ($field->kind === ContentField::HTML) {
                $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
            }
            if ($value === '') {
                continue;
            }
            $parts[] = $field->label . ': ' . $value;
        }

        return mb_substr(implode("\n", $parts), 0, self::BODY_LIMIT);
    }

    private function renderer(): PromptRenderer
    {
        return $this->renderer ??= new PromptRenderer();
    }
}
