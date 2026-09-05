<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentHtmlSanitizer;

/**
 * What a rich field may store.
 *
 * The editor on the page is not the boundary — a form post carries whatever
 * the client decides to send, whatever the widget allowed. These cases are
 * therefore written as if no editor existed: raw payloads in, allowlist out.
 */
final class ContentHtmlSanitizerTest extends TestCase
{
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

        self::assertSame('a < b & c', $out['title']);
        self::assertStringNotContainsString('script', $out['body']);
    }
}
