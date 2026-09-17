<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Domain\Model\ContentBlock;

/**
 * An uppercase `<IMG>` is a picture on BOTH sides of the save.
 *
 * HTML tag names are case-insensitive and stored pages are not rewritten: a
 * page can hold `<IMG>` until its author next touches the editor. The server
 * learned that (`stripos`), and the console's collect() — which decides what
 * reaches the hidden field on submit — still tested for the lowercase spelling
 * only. The block was dropped, picture and all, the first time anything else
 * on the page was saved, and the save reported success.
 *
 * The browser half is asserted structurally, the way this package asserts its
 * other client rules: the file is what ships, and the rule it must carry is a
 * case-insensitive test.
 */
final class CollectKeepsAnUppercaseImageTest extends TestCase
{
    #[Test]
    public function theServerReadsAnUppercaseImageAsContent(): void
    {
        self::assertFalse(ContentBlock::text('<div><IMG SRC="/os/app/cms/media/a"></div>')->isEmpty());
    }

    #[Test]
    public function theConsoleTestsForAnImageWithoutRegardToCase(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../src/Application/Static/js/content-blocks.js');

        self::assertMatchesRegularExpression(
            '#/<img\\\\b/i\\.test\(#',
            $js,
            'collect() must not test for the lowercase spelling alone'
        );
        self::assertStringNotContainsString(
            "indexOf('<img')",
            $js,
            'the lowercase-only test is what dropped the block'
        );
    }
}
