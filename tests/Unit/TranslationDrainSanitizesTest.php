<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentHtmlSanitizer;
use Semitexa\Cms\Application\Service\TranslationDrain;
use Semitexa\Cms\Domain\Contract\ContentOverlayTranslatorInterface;
use Semitexa\Cms\Domain\Model\TranslatedValue;

/** A model told by an author's text to add a script wrote it to every visitor of that language. */
final class TranslationDrainSanitizesTest extends TestCase
{
    #[Test]
    public function what_a_model_writes_as_markup_is_sanitised_before_it_is_stored(): void
    {
        $translator = new class implements ContentOverlayTranslatorInterface {
            public function translatorId(): string { return 'test:page'; }
            public function fingerprint(string $ref): ?string { return 'h'; }
            public function translate(string $ref): void { throw new \LogicException('not called'); }
            public function translations(string $ref): array
            {
                return [
                    new TranslatedValue('uk', 'body', '<p>Привіт</p><script>steal()</script><a href="javascript:alert(1)">x</a>', html: true),
                    new TranslatedValue('uk', 'title', '<b>raw title</b>'),
                ];
            }
            public function storeTranslations(string $ref, array $values): void {}
        };
        $drain = new TranslationDrain();
        (new \ReflectionProperty(TranslationDrain::class, 'sanitizer'))->setValue($drain, new ContentHtmlSanitizer());

        $values = (new \ReflectionMethod(TranslationDrain::class, 'sanitizedTranslations'))->invoke($drain, $translator, 'page-1');

        self::assertStringContainsString('<p>Привіт</p>', $values[0]->value);
        self::assertStringNotContainsString('script', $values[0]->value);
        self::assertStringNotContainsString('javascript:', $values[0]->value);
        self::assertSame('<b>raw title</b>', $values[1]->value, 'a plain-text field is the translator\'s to escape when it prints it');
    }
}
