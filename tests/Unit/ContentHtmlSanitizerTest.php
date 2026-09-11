<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentHtmlSanitizer;
use Semitexa\Cms\Application\Service\ContentImageSources;
use Semitexa\Media\Domain\Contract\MediaUrlGeneratorInterface;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * What a rich field may store.
 *
 * The editor on the page is not the boundary — a form post carries whatever
 * the client decides to send, whatever the widget allowed. These cases are
 * therefore written as if no editor existed: raw payloads in, allowlist out.
 */
final class ContentHtmlSanitizerTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private ContentHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new ContentHtmlSanitizer();
    }

    /** Everything the vendored editor can produce has to survive untouched. */
    #[Test]
    public function the_editors_own_output_passes_through(): void
    {
        $html = '<div>Музей у <strong>Львові</strong>.</div>'
            . '<blockquote>Цитата</blockquote>'
            . '<ul><li>раз</li><li>два</li></ul>'
            . '<ol><li>крок</li></ol>'
            . '<h1>Заголовок</h1>'
            . '<pre language="php">echo 1;</pre>'
            . '<div><del>старе</del> <em>нове</em></div>';

        self::assertSame($html, $this->sanitizer->sanitize($html));
    }

    #[Test]
    public function links_the_site_needs_are_kept(): void
    {
        $out = $this->sanitizer->sanitize(
            '<div><a href="https://example.test/x">a</a><a href="/contacts">b</a></div>',
        );

        self::assertStringContainsString('href="https://example.test/x"', $out);
        self::assertStringContainsString('href="/contacts"', $out);
    }

    /**
     * The check people skip. A link scheme is where a "safe" rich-text field
     * turns into an execution surface, and no amount of tag allowlisting
     * catches it.
     */
    #[Test]
    #[DataProvider('executableSchemes')]
    public function an_executable_link_scheme_loses_its_href(string $href): void
    {
        $out = $this->sanitizer->sanitize('<div><a href="' . $href . '">клік</a></div>');

        self::assertStringNotContainsString('href', $out);
        self::assertStringContainsString('клік', $out, 'The text was never the dangerous part.');
    }

    /** @return iterable<string, array{string}> */
    public static function executableSchemes(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
    }

    #[Test]
    #[DataProvider('payloads')]
    public function a_payload_cannot_reach_storage(string $input, string $mustNotContain): void
    {
        self::assertStringNotContainsString($mustNotContain, $this->sanitizer->sanitize($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function payloads(): iterable
    {
        yield 'script element' => ['<div>до<script>alert(1)</script>після</div>', 'alert'];
        yield 'img onerror' => ['<div><img src=x onerror=alert(1)></div>', 'onerror'];
        yield 'svg script' => ['<div><svg><script>alert(1)</script></svg></div>', 'svg'];
        yield 'event attribute' => ['<div onclick="alert(1)">текст</div>', 'onclick'];
        yield 'style attribute' => ['<div style="position:fixed;inset:0">текст</div>', 'style'];
        yield 'iframe' => ['<div><iframe src="https://evil.test"></iframe></div>', 'iframe'];
        yield 'form' => ['<div><form action="https://evil.test"><input name="p"></form></div>', 'form'];
        yield 'base tag' => ['<base href="https://evil.test/">', 'base'];
    }

    /** An uploaded image, addressed the only way stored markup may address one. */
    #[Test]
    public function an_image_on_our_own_media_route_is_kept(): void
    {
        $out = $this->sanitizer->sanitize(
            '<figure><img src="/os/app/cms/media/01J8ABC-xyz" alt="Музей" width="800" height="600"><figcaption>Підпис</figcaption></figure>',
        );

        self::assertStringContainsString('src="/os/app/cms/media/01J8ABC-xyz"', $out);
        self::assertStringContainsString('alt="Музей"', $out);
        self::assertStringContainsString('<figcaption>Підпис</figcaption>', $out);
    }

    /**
     * An image pointing anywhere else does not belong in an article: a tracking
     * pixel, a request that leaves with the reader's address, or simply a
     * picture that vanishes when somebody else's server does.
     *
     * The evasions matter as much as the plain case — a prefix match would let
     * the traversal through, a suffix match the foreign host.
     */
    #[Test]
    #[DataProvider('foreignImageSources')]
    public function an_image_pointing_elsewhere_is_dropped(string $src): void
    {
        $out = $this->sanitizer->sanitize('<div><img src="' . $src . '" alt="x"></div>');

        self::assertStringNotContainsString('<img', $out);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignImageSources(): iterable
    {
        yield 'foreign host' => ['https://evil.test/pixel.png'];
        yield 'protocol relative' => ['//evil.test/p.png'];
        yield 'path traversal' => ['/os/app/cms/media/../../../etc/passwd'];
        yield 'our path as a suffix' => ['https://evil.test/os/app/cms/media/x'];
        yield 'data uri' => ['data:image/svg+xml;base64,PHN2Zz48c2NyaXB0Pg=='];
    }

    /**
     * Dropping the image should drop the place it sat. An empty figure still
     * takes vertical space in the rendered article, so the reader gets a gap
     * where a picture was refused and the author a hole they cannot select.
     */
    #[Test]
    public function refusing_an_image_does_not_leave_its_frame_behind(): void
    {
        $out = $this->sanitizer->sanitize('<figure><img src="https://evil.test/p.png"></figure><div>текст</div>');

        self::assertStringNotContainsString('figure', $out);
        self::assertStringContainsString('<div>текст</div>', $out);
    }

    /** A figure that still holds something is left alone. */
    #[Test]
    public function a_figure_with_a_caption_survives(): void
    {
        $out = $this->sanitizer->sanitize(
            '<figure><img src="/os/app/cms/media/a"><figcaption>Підпис</figcaption></figure>',
        );

        self::assertStringContainsString('<figcaption>Підпис</figcaption>', $out);
    }

    /** No src at all is not an image, whatever else it carries. */
    #[Test]
    public function an_image_without_a_source_is_dropped(): void
    {
        self::assertStringNotContainsString('<img', $this->sanitizer->sanitize('<div><img alt="x"></div>'));
    }

    /** A valid src does not buy the rest of the tag any leniency. */
    #[Test]
    public function an_event_attribute_does_not_survive_on_a_valid_image(): void
    {
        $out = $this->sanitizer->sanitize('<div><img src="/os/app/cms/media/abc" onerror="alert(1)"></div>');

        self::assertStringContainsString('src="/os/app/cms/media/abc"', $out);
        self::assertStringNotContainsString('onerror', $out);
    }

    /** Only declared markup fields are touched; plain text keeps its characters. */
    #[Test]
    public function a_plain_field_is_left_alone(): void
    {
        $values = ['title' => 'a < b & c', 'body' => '<div>ok</div><script>x</script>'];

        $out = $this->sanitizer->sanitizeValues($values, ['body']);

        self::assertSame('a < b & c', $out->values['title']);
        self::assertStringNotContainsString('script', $out->values['body']);
        self::assertSame([], $out->refusedImageSources, 'nothing was an image');
        self::assertNull($out->notice(), 'and a notice about nothing trains people to ignore notices');
    }

    /**
     * The other half of the failure. Widening the allowlist stopped the
     * LEGITIMATE pictures disappearing; this is what happens to the rest —
     * they still cannot be stored, but the author finds out while they are
     * still looking at the editor rather than by reopening the article later.
     */
    #[Test]
    public function a_save_that_drops_pictures_says_which(): void
    {
        $out = $this->sanitizer->sanitizeValues([
            'body' => '<div><img src="https://evil.test/a.png"><img src="https://other.test/b.png">текст</div>',
        ], ['body']);

        self::assertSame(
            ['https://evil.test/a.png', 'https://other.test/b.png'],
            $out->refusedImageSources,
            'in document order — an author reading the message is looking for a picture on the page',
        );

        $notice = (string) $out->notice();

        self::assertStringContainsString('2 шт.', $notice);
        self::assertStringContainsString('https://evil.test/a.png', $notice);
        self::assertStringContainsString('https://other.test/b.png', $notice);
        self::assertStringContainsString('текст', $out->values['body'], 'the words were never the problem');
    }

    /** The same picture twice is one thing to fix, not two. */
    #[Test]
    public function the_same_refused_address_is_reported_once(): void
    {
        $out = $this->sanitizer->sanitizeValues([
            'body' => '<div><img src="https://evil.test/a.png"><img src="https://evil.test/a.png"></div>',
        ], ['body']);

        self::assertSame(['https://evil.test/a.png'], $out->refusedImageSources);
    }

    /**
     * Markup that showed nothing is not a loss. Reporting it would put a
     * warning on saves where nothing happened, which is how a warning stops
     * being read.
     */
    #[Test]
    public function an_image_that_displayed_nothing_is_not_reported(): void
    {
        $out = $this->sanitizer->sanitizeValues([
            'body' => '<div><img alt="нічого">текст</div>',
        ], ['body']);

        self::assertSame([], $out->refusedImageSources);
        self::assertNull($out->notice());
    }

    /** Every field is accounted for, not only the first one that lost something. */
    #[Test]
    public function refusals_from_every_markup_field_are_collected(): void
    {
        $out = $this->sanitizer->sanitizeValues([
            'intro' => '<div><img src="https://a.test/1.png"></div>',
            'body' => '<div><img src="https://b.test/2.png"></div>',
        ], ['intro', 'body']);

        self::assertSame(['https://a.test/1.png', 'https://b.test/2.png'], $out->refusedImageSources);
    }

    /**
     * HtmlSanitizer truncates at 20 000 bytes by default and returns the prefix
     * as though it were the document — so a long article was saved cut off,
     * mid-tag, under a «Збережено.» message. Nothing in the stored value would
     * have told the author, and only reopening it would.
     */
    /**
     * A `data:` image is reported, not erased in silence.
     *
     * This is the case the reporting missed for the reason it was easiest to
     * miss. The scheme allowlist permits http and https only, so Symfony does
     * not reject the image — it removes the `src` attribute — and what the
     * ownership pass then sees is an <img> with no source at all, which it
     * deliberately says nothing about (markup that displayed nothing is not
     * worth a warning). A pasted screenshot therefore disappeared under
     * «Збережено.» with no notice naming it.
     */
    #[Test]
    public function an_inline_data_image_is_named_rather_than_quietly_erased(): void
    {
        $out = $this->sanitizer->sanitizeValues([
            'body' => '<div><img src="data:image/png;base64,iVBORw0KGgo="></div>',
        ], ['body']);

        self::assertSame(['data:image/png;base64,iVBORw0KGgo='], $out->refusedImageSources);
        self::assertStringNotContainsString('<img', $out->values['body']);
        self::assertNotNull($out->notice());
    }

    /** An <img> that never carried a source is still not worth a warning. */
    #[Test]
    public function an_image_with_no_source_at_all_is_still_passed_over(): void
    {
        $out = $this->sanitizer->sanitizeValues(['body' => '<div><img alt="nothing"></div>'], ['body']);

        self::assertSame([], $out->refusedImageSources);
        self::assertNull($out->notice());
    }

    #[Test]
    public function a_document_past_the_librarys_own_limit_survives_whole(): void
    {
        $paragraph = '<div>Музей у Львові має довгу історію.</div>';
        $long = str_repeat($paragraph, 2000); // ~86 KB, well past 20 000

        self::assertGreaterThan(20_000, strlen($long), 'the fixture must actually cross the default');

        $clean = $this->sanitizer->sanitize($long);

        self::assertSame(2000, substr_count($clean, '<div>'), 'every paragraph must come back');
        self::assertStringEndsWith('</div>', $clean, 'and the end must not be a cut');
    }

    #[Test]
    public function a_document_over_our_own_limit_is_refused_rather_than_trimmed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/завелик/u');

        $this->sanitizer->sanitize(str_repeat('a', 200_001));
    }

    /** The limit itself is allowed — a boundary that refuses at the number is a different limit. */
    #[Test]
    public function a_document_exactly_at_the_limit_is_accepted(): void
    {
        $filler = str_repeat('a', 200_000 - strlen('<div></div>'));

        self::assertStringContainsString($filler, $this->sanitizer->sanitize('<div>' . $filler . '</div>'));
    }

    /**
     * The failure the whole allowlist widening exists for.
     *
     * A record whose pictures were not put there by this editor — a module
     * older than the CMS, a page rendered straight through MediaUrlGenerator —
     * opened in the console and saved came back with every image gone, under
     * «Збережено.» and with nothing said. An address the installation itself
     * publishes has to survive the round trip.
     */
    #[Test]
    public function an_image_this_installation_publishes_itself_survives_a_save(): void
    {
        $src = 'https://cdn.example.test/bucket/media/tenant-a/content/a1/content.webp?v=1757500000';

        $out = $this->sanitizerPublishing('https://cdn.example.test/bucket/media/tenant-a/')
            ->sanitize('<figure><img src="' . $src . '" alt="Музей"><figcaption>Підпис</figcaption></figure>');

        // Entity-decoded before the comparison: the library writes `=` inside
        // an attribute as `&#61;`, which is the same address to every browser
        // and to the next pass of this sanitizer, but not to a string compare.
        self::assertStringContainsString(
            'src="' . $src . '"',
            html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
        self::assertStringContainsString('<figcaption>Підпис</figcaption>', $out);

        // And it still passes on the way back in — an author who saves twice
        // must not lose on the second press what survived the first.
        self::assertStringContainsString(
            '<img',
            $this->sanitizerPublishing('https://cdn.example.test/bucket/media/tenant-a/')->sanitize($out),
        );
    }

    /**
     * Letting http(s) through the library's scheme gate moved the decision
     * here — so the foreign host has to be refused by THIS class even on an
     * installation that publishes its media over the same scheme.
     */
    #[Test]
    public function a_foreign_host_is_still_refused_where_media_is_published(): void
    {
        $out = $this->sanitizerPublishing('https://cdn.example.test/bucket/media/tenant-a/')
            ->sanitize('<div><img src="https://evil.test/pixel.png" alt="x"></div>');

        self::assertStringNotContainsString('<img', $out);
    }

    /** A sanitizer on an installation whose media is published under `$prefix`. */
    private function sanitizerPublishing(string $prefix): ContentHtmlSanitizer
    {
        $sources = self::createWithDependencies(ContentImageSources::class, [
            'media' => new class ($prefix) implements MediaUrlGeneratorInterface {
                public function __construct(private readonly string $prefix)
                {
                }

                public function url(string $assetId, ?string $variantKey = null): string
                {
                    return $this->prefix . $assetId;
                }

                public function publicUrlPrefix(): string
                {
                    return $this->prefix;
                }
            },
        ]);

        return self::createWithDependencies(ContentHtmlSanitizer::class, ['sources' => $sources]);
    }
}
