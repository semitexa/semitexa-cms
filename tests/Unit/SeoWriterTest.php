<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\SeoWriter;
use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;
use Semitexa\Cms\Domain\Model\ContentSeo;
use Semitexa\Llm\Application\Service\LlmProviderResolver;
use Semitexa\Llm\Domain\Contract\LlmProviderInterface;
use Semitexa\Llm\Domain\Model\LlmRequest;
use Semitexa\Llm\Domain\Model\LlmResponse;
use Semitexa\Prompt\Application\Service\PromptRegistry;
use Semitexa\Prompt\Domain\Contract\PromptRepositoryInterface;
use Semitexa\Cms\Application\Prompt\SeoWriterPrompt;
use Semitexa\Prompt\Domain\Model\PromptTemplate;

/**
 * Nothing a model says reaches a page unchecked. What it is asked for, what
 * survives the reply, and what happens when it cannot answer at all.
 */
final class SeoWriterTest extends TestCase
{
    private string $lastSystemPrompt = '';

    #[Test]
    public function it_writes_the_metadata_a_page_does_not_have_yet(): void
    {
        $seo = $this->writer('{"title":"Про музей","description":"Краєзнавчий музей у Львові, відкритий з вівторка по неділю.","ogTitle":"Про музей","ogDescription":"Краєзнавчий музей у Львові.","jsonLd":"{\"@context\":\"https://schema.org\",\"@type\":\"Museum\"}"}')
            ->write(new ContentSeo(ref: 'page:about'), $this->draft(), 'hash-1');

        self::assertSame('Про музей', $seo->title);
        self::assertStringContainsString('Краєзнавчий музей', $seo->description);
        self::assertStringContainsString('"@type":"Museum"', $seo->jsonLd);
        self::assertSame('hash-1', $seo->sourceHash);
    }

    /**
     * The model is never shown a field somebody claimed — it cannot be tempted
     * to rewrite what it was not asked about.
     */
    #[Test]
    public function an_authored_field_is_not_even_requested(): void
    {
        $seo = (new ContentSeo(ref: 'page:about'))->withAuthored(['description' => 'ours']);

        $this->writer('{"title":"Про музей"}')->write($seo, $this->draft(), 'hash-1');

        self::assertStringNotContainsString('description":"…"', $this->lastSystemPrompt);
        self::assertStringContainsString('Requested fields: title, ogTitle, ogDescription, jsonLd', $this->lastSystemPrompt);
    }

    #[Test]
    public function a_page_with_every_field_claimed_never_calls_the_model(): void
    {
        $seo = (new ContentSeo(ref: 'page:about'))->withAuthored(
            array_combine(ContentSeo::GENERATED_FIELDS, array_fill(0, count(ContentSeo::GENERATED_FIELDS), 'ours')),
        );

        $writer = $this->writer('{"description":"should never be asked for"}', function (): void {
            self::fail('the model must not be called when there is nothing to generate');
        });

        self::assertSame($seo, $writer->write($seo, $this->draft(), 'hash-1'));
    }

    #[Test]
    public function an_empty_page_is_left_alone_rather_than_described(): void
    {
        $empty = new ContentDraft(ref: 'page:about', title: 'About', fields: []);

        $writer = $this->writer('{"description":"A wonderful page."}', function (): void {
            self::fail('a model asked to describe nothing will invent something');
        });

        self::assertTrue($writer->write(new ContentSeo(ref: 'page:about'), $empty, 'h')->isEmpty());
    }

    /**
     * What a live model actually returns. Requiring a string here silently
     * dropped a complete, correct schema.org graph on every real page — the
     * unit tests all passed because they fed it the shape we assumed.
     */
    #[Test]
    public function structured_data_is_accepted_as_an_object_not_only_as_a_string(): void
    {
        $seo = $this->writer('{"description":"d","jsonLd":{"@context":"https://schema.org","@type":"Museum","name":"Краєзнавчий музей"}}')
            ->write(new ContentSeo(ref: 'page:about'), $this->draft(), 'h');

        self::assertStringContainsString('"@type":"Museum"', $seo->jsonLd);
        self::assertStringContainsString('Краєзнавчий музей', $seo->jsonLd, 'stored unescaped, ready for a script tag');
    }

    #[Test]
    public function an_empty_structured_data_object_is_not_stored(): void
    {
        $seo = $this->writer('{"description":"d","jsonLd":{}}')
            ->write(new ContentSeo(ref: 'page:about'), $this->draft(), 'h');

        self::assertSame('', $seo->jsonLd);
    }

    /**
     * Structured data that does not parse is a liability on the page — a search
     * engine flags it, where absence costs nothing.
     */
    #[Test]
    public function structured_data_that_is_not_json_is_dropped_not_stored(): void
    {
        $seo = $this->writer('{"description":"A real description.","jsonLd":"{@type: Museum,,,"}')
            ->write(new ContentSeo(ref: 'page:about'), $this->draft(), 'h');

        self::assertSame('', $seo->jsonLd);
        self::assertSame('A real description.', $seo->description, 'the rest of the reply still lands');
    }

    #[Test]
    public function an_overlong_description_is_cut_at_a_word_boundary(): void
    {
        $long = str_repeat('museum ', 60);
        $seo = $this->writer('{"description":"' . trim($long) . '"}')
            ->write(new ContentSeo(ref: 'page:about'), $this->draft(), 'h');

        self::assertLessThanOrEqual(ContentSeo::DESCRIPTION_LIMIT, mb_strlen($seo->description));
        self::assertStringEndsWith('museum', $seo->description, 'never cut mid-word');
    }

    /**
     * A provider blinking must not blank metadata that was already good. The
     * queue retries; an empty overwrite is unrecoverable.
     */
    #[Test]
    public function a_provider_failure_raises_rather_than_returning_nothing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not reach the model/');

        $this->writer('', success: false)->write(new ContentSeo(ref: 'page:about'), $this->draft(), 'h');
    }

    #[Test]
    public function a_reply_that_is_not_json_raises_rather_than_being_guessed_at(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/was not JSON/');

        $this->writer('Sure! Here is your metadata:')->write(new ContentSeo(ref: 'page:about'), $this->draft(), 'h');
    }

    #[Test]
    public function markup_is_stripped_before_the_page_is_shown_to_the_model(): void
    {
        $this->writer('{"description":"d"}')->write(new ContentSeo(ref: 'page:about'), $this->draft(), 'h');

        self::assertStringContainsString('open Tuesday to Sunday', $this->lastSystemPrompt);
        self::assertStringNotContainsString('<p>', $this->lastSystemPrompt);
    }

    private function draft(): ContentDraft
    {
        return new ContentDraft(
            ref: 'page:about',
            title: 'About',
            fields: [
                ContentField::line('heading', 'Heading', 'About the museum'),
                ContentField::html('body', 'Body', '<p>A regional museum in Lviv, <b>open Tuesday to Sunday</b>.</p>'),
            ],
            publicUrl: 'https://example.org/about',
        );
    }

    private function writer(string $reply, ?\Closure $onCall = null, bool $success = true): SeoWriter
    {
        $test = $this;
        $provider = new class($reply, $success, $onCall, $test) implements LlmProviderInterface {
            public function __construct(
                private string $reply,
                private bool $ok,
                private ?\Closure $onCall,
                private SeoWriterTest $test,
            ) {}

            public function name(): string
            {
                return 'fake';
            }

            public function baseUrl(): string
            {
                return '';
            }

            public function model(): string
            {
                return 'fake';
            }

            public function healthCheck(): bool
            {
                return true;
            }

            public function complete(LlmRequest $request): LlmResponse
            {
                ($this->onCall ?? static fn () => null)();
                $this->test->rememberPrompt($request->systemPrompt);

                return $this->ok
                    ? new LlmResponse($this->reply, true)
                    : new LlmResponse('', false, 'provider unreachable');
            }
        };

        $writer = new SeoWriter();
        (new \ReflectionProperty(SeoWriter::class, 'providers'))
            ->setValue($writer, (new LlmProviderResolver())->withProvider($provider));
        (new \ReflectionProperty(SeoWriter::class, 'prompts'))->setValue($writer, $this->catalog());

        return $writer;
    }

    public function rememberPrompt(string $system): void
    {
        $this->lastSystemPrompt = $system;
    }

    /** A one-entry repository so rendering stays hermetic. */
    private function catalog(): PromptRepositoryInterface
    {
        $templates = (new PromptRegistry())->buildFromClasses([SeoWriterPrompt::class]);

        return new class($templates) implements PromptRepositoryInterface {
            /** @param array<string, PromptTemplate> $templates */
            public function __construct(private array $templates) {}

            public function get(string $id): PromptTemplate
            {
                return $this->templates[$id];
            }

            public function tryGet(string $id): ?PromptTemplate
            {
                return $this->templates[$id] ?? null;
            }

            public function has(string $id): bool
            {
                return isset($this->templates[$id]);
            }

            public function all(): array
            {
                return array_values($this->templates);
            }
        };
    }
}
