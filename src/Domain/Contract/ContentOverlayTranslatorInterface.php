<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Contract;

use Semitexa\Cms\Domain\Model\TranslatedValue;

/**
 * A translator that returns what the model wrote instead of storing it.
 *
 * With translate() the CMS never saw the overlay: a model told by an author's
 * text to add a script wrote it straight to every visitor of that language.
 * Here the CMS sanitises every HTML value (ContentHtmlSanitizer) and only then
 * hands them back to storeTranslations(). translate() is not called for a
 * translator of this kind.
 */
interface ContentOverlayTranslatorInterface extends ContentTranslatorInterface
{
    /**
     * The other languages of $ref, translated from its current text. Untrusted.
     *
     * @return list<TranslatedValue>
     * @throws \RuntimeException when the work could not be done and is worth retrying
     */
    public function translations(string $ref): array;

    /**
     * Store them as $ref's overlay; HTML values are sanitised already.
     *
     * @param list<TranslatedValue> $values
     */
    public function storeTranslations(string $ref, array $values): void;
}
