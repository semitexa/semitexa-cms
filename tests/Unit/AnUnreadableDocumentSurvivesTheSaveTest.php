<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentBlockCodec;
use Semitexa\Cms\Application\Service\ContentBlocksControl;
use Semitexa\Cms\Application\Service\ContentHtmlSanitizer;

/**
 * Preserving an unreadable document is only worth anything if the SAVE keeps it.
 *
 * `decode()` already answered a document it could not read with the whole value
 * as one text block, so nothing vanished from the screen. Everything after it
 * then undid that: the sanitizer re-encoded the fallback block, writing the
 * document's own bytes as the payload of a fresh v1 text block, and the editor
 * did the same on submit. So a page written by a newer console was destroyed by
 * opening it in an older one and saving the TITLE — and the guarantee this
 * format advertises held only until someone used it.
 */
final class AnUnreadableDocumentSurvivesTheSaveTest extends TestCase
{
    /** A block this version has never heard of, inside a well-formed envelope. */
    private const FROM_A_NEWER_CONSOLE =
        '{"format":"semitexa.cms.blocks/v1","blocks":[{"kind":"video","payload":"<div>Hi</div>","src":"/v/1"}]}';

    #[Test]
    public function theCodecCallsItUnreadableRatherThanReinterpretingIt(): void
    {
        $codec = new ContentBlockCodec();

        self::assertTrue($codec->isUnreadableDocument(self::FROM_A_NEWER_CONSOLE));
        self::assertSame(self::FROM_A_NEWER_CONSOLE, $codec->decode(self::FROM_A_NEWER_CONSOLE)[0]->payload);
    }

    #[Test]
    public function theSanitizerReturnsItByteForByte(): void
    {
        $clean = (new ContentHtmlSanitizer())
            ->sanitizeValues(['body' => self::FROM_A_NEWER_CONSOLE], [], ['body']);

        self::assertSame(
            self::FROM_A_NEWER_CONSOLE,
            $clean->values['body'],
            'sanitising the fallback block stored the document inside a new one'
        );
    }

    #[Test]
    public function theEditorMarksTheFieldOpaqueSoTheClientLeavesItAlone(): void
    {
        $html = (new ContentBlocksControl())->render('body', self::FROM_A_NEWER_CONSOLE, '', 0, self::picker());

        self::assertStringContainsString('data-blocks-opaque="1"', $html);
    }

    #[Test]
    public function aDocumentThisVersionCanReadIsNotOpaqueAndIsStillCleaned(): void
    {
        // The other half: the guarantee must not turn into "never sanitise
        // anything". A readable document goes through the allowlist as before.
        $readable = '{"format":"semitexa.cms.blocks/v1","blocks":[{"kind":"text","payload":"<div onclick=\"x()\">Hi</div>"}]}';

        $out = (new ContentHtmlSanitizer())
            ->sanitizeValues(['body' => $readable], [], ['body'])->values['body'];

        self::assertFalse((new ContentBlockCodec())->isUnreadableDocument($readable));
        self::assertStringNotContainsString('onclick', $out);
        self::assertStringContainsString('Hi', $out);
        self::assertStringNotContainsString(
            'data-blocks-opaque',
            (new ContentBlocksControl())->render('body', $readable, '', 0, self::picker()),
        );
    }

    /** @return callable(string): string */
    private static function picker(): callable
    {
        return static fn (string $asset): string => '<div class="cover">' . $asset . '</div>';
    }
}
