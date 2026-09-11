<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semitexa\Cms\Application\Handler\PayloadHandler\ContentSaveHandler;
use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;

/**
 * What a save tells the author, and what it checks before believing them.
 *
 * A save is not one write. The text goes into the record first; the search
 * metadata, the translation queue and the SEO queue are separate work on a
 * store this handler cannot enrol in one transaction with it. The two halves
 * therefore fail differently, and the page has to say which half failed.
 */
final class ContentSaveReportingTest extends TestCase
{
    /** @return array{?string, ?string} */
    private function report(bool $contentSaved, ?string $warning, string $before, string $after): array
    {
        return (new ReflectionMethod(ContentSaveHandler::class, 'reportFailure'))
            ->invoke(null, $contentSaved, $warning, $before, $after);
    }

    private function malformedDate(ContentDraft $draft, array $values): ?string
    {
        return (new ReflectionMethod(ContentSaveHandler::class, 'malformedDate'))
            ->invoke(null, $draft, $values);
    }

    /** Nothing was written, so the page says nothing was written. */
    #[Test]
    public function a_failure_before_the_write_is_reported_as_a_failed_save(): void
    {
        [$error, $warning] = $this->report(false, null, 'Не вдалося зберегти.', 'Метадані — ні.');

        self::assertSame('Не вдалося зберегти.', $error);
        self::assertNull($warning);
    }

    /**
     * The text IS in the record. Reporting that as a failed save sends the
     * author back to retype what is already there, or to hunt for damage that
     * does not exist — and the next thing they do is save again over their own
     * good copy.
     */
    #[Test]
    public function a_failure_after_the_write_does_not_claim_the_save_failed(): void
    {
        [$error, $warning] = $this->report(true, null, 'Не вдалося зберегти.', 'Метадані — ні.');

        self::assertNull($error, 'the content reached the record; saying otherwise is a lie about the data');
        self::assertSame('Метадані — ні.', $warning);
    }

    /**
     * Two separate facts about one save. The pictures an allowlist refused and
     * the metadata that did not store are both things the author needs, and
     * neither replaces the other.
     */
    #[Test]
    public function a_later_failure_is_added_to_the_notice_rather_than_replacing_it(): void
    {
        [$error, $warning] = $this->report(true, 'Зображення вилучено.', 'Не вдалося.', 'Метадані — ні.');

        self::assertNull($error);
        self::assertSame('Зображення вилучено. Метадані — ні.', $warning);
    }

    /**
     * The date is checked exactly as it will be stored.
     *
     * Validating a trimmed copy while saving the original is how « 2026-09-11 »
     * passed this gate and landed in the record with its spaces — where
     * ContentField::isCalendarDay(), the very same test, calls it malformed on
     * the way back out.
     */
    #[Test]
    public function a_padded_date_is_not_accepted_by_a_gate_that_stores_the_padding(): void
    {
        $draft = new ContentDraft(
            ref: 'demo:article:x',
            title: 'Подія',
            fields: [ContentField::date('starts_on', 'Починається')],
        );

        self::assertNotNull($this->malformedDate($draft, ['starts_on' => ' 2026-09-11 ']));
        self::assertNotNull($this->malformedDate($draft, ['starts_on' => "2026-09-11\n"]));
        self::assertNull($this->malformedDate($draft, ['starts_on' => '2026-09-11']));
    }

    /**
     * Nothing entered is the EXACT empty string — what a blank date input
     * submits, and what a missing field resolves to. Whether that is allowed is
     * the required check's question, not this one.
     *
     * Whitespace is not nothing. « » is a value the record would store, and
     * skipping it here would put spaces in a date column through the very gate
     * that exists to keep them out.
     */
    #[Test]
    public function nothing_entered_is_not_a_malformed_date(): void
    {
        $draft = new ContentDraft(
            ref: 'demo:article:x',
            title: 'Подія',
            fields: [ContentField::date('starts_on', 'Починається')],
        );

        self::assertNull($this->malformedDate($draft, ['starts_on' => '']));
        self::assertNull($this->malformedDate($draft, []));

        self::assertNotNull($this->malformedDate($draft, ['starts_on' => '   ']));
        self::assertNotNull($this->malformedDate($draft, ['starts_on' => "\t"]));
    }
}
