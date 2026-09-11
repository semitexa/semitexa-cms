<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentEditorPage;
use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;

/**
 * A day an author can set where they edit the text.
 *
 * A page's schedule used to be reachable only from a chat command or from SQL.
 * The value is ISO on the wire and the reader's own locale on screen, which is
 * what a native date input already does — so the interesting part is not the
 * widget, it is the rule that a value nobody could have meant never reaches a
 * module.
 */
final class ContentDateFieldTest extends TestCase
{
    #[Test]
    public function a_date_is_carried_as_iso_and_nothing_else(): void
    {
        $field = ContentField::date('starts_on', 'Починається', ' 2026-09-11 ');

        self::assertSame(ContentField::DATE, $field->kind);
        self::assertSame('2026-09-11', $field->value, 'trimmed, not reformatted');
    }

    /** No date is a value an author can send back, not an omission. */
    #[Test]
    public function an_empty_date_is_allowed_and_means_no_date(): void
    {
        self::assertSame('', ContentField::date('ends_on', 'Завершується')->value);
        self::assertTrue(ContentField::isCalendarDay(''));
    }

    #[Test]
    #[DataProvider('realDays')]
    public function a_real_day_is_accepted(string $value): void
    {
        self::assertTrue(ContentField::isCalendarDay($value));
    }

    /** @return iterable<string, array{string}> */
    public static function realDays(): iterable
    {
        yield 'an ordinary day' => ['2026-09-11'];
        yield 'the first of a month' => ['2026-01-01'];
        yield 'a leap day in a leap year' => ['2024-02-29'];
    }

    /**
     * The case a bare parse would miss. `createFromFormat` reads '2026-02-31'
     * happily and hands back March 3rd — so without the round trip a day nobody
     * could have meant would be stored as a DIFFERENT day, silently.
     */
    #[Test]
    #[DataProvider('notDays')]
    public function a_value_nobody_could_have_meant_is_refused(string $value): void
    {
        self::assertFalse(ContentField::isCalendarDay($value));
    }

    /** @return iterable<string, array{string}> */
    public static function notDays(): iterable
    {
        yield 'a day that month does not have' => ['2026-02-31'];
        yield 'a leap day in a common year' => ['2026-02-29'];
        yield 'a thirteenth month' => ['2026-13-01'];
        yield 'a display format' => ['11.09.2026'];
        yield 'a datetime' => ['2026-09-11 10:00:00'];
        yield 'prose' => ['завтра'];
        yield 'a year alone' => ['2026'];
        yield 'unpadded' => ['2026-9-1'];
    }

    /** The console renders the platform's own picker rather than one of its own. */
    #[Test]
    public function the_editor_renders_a_native_date_control(): void
    {
        $page = new ContentEditorPage();

        $html = $page->render(
            new ContentDraft(
                ref: 'demo:article:x',
                title: 'Подія',
                fields: [
                    ContentField::line('title', 'Заголовок', 'Подія', true),
                    ContentField::html('body', 'Текст', '<div>Текст.</div>', true),
                    ContentField::date('starts_on', 'Починається', '2026-09-11'),
                    ContentField::date('ends_on', 'Завершується'),
                ],
            ),
            'token',
        );

        self::assertStringContainsString('<input type="date" name="starts_on" value="2026-09-11">', $html);
        self::assertStringContainsString('<input type="date" name="ends_on" value="">', $html);
        self::assertStringContainsString('Завершується', $html);
    }

    /**
     * A stored value the date control cannot represent is shown as text.
     *
     * `type="date"` does not display «2026-02-31» — it comes up EMPTY and
     * submits an empty string. So an author who opened the record to fix a typo
     * in the title would have cleared a date they never looked at, under
     * «Збережено.», with nothing to indicate it. Rendered as text the bad value
     * is visible, survives the round trip, and the save gate reports it as the
     * malformed date it is.
     */
    #[Test]
    public function a_stored_value_the_date_control_cannot_show_is_not_silently_cleared(): void
    {
        $html = (new ContentEditorPage())->render(
            new ContentDraft(
                ref: 'demo:article:x',
                title: 'Подія',
                fields: [
                    ContentField::line('title', 'Заголовок', 'Подія', true),
                    ContentField::date('starts_on', 'Починається', '2026-02-31'),
                ],
            ),
            'token',
        );

        self::assertStringNotContainsString('<input type="date" name="starts_on"', $html);
        self::assertStringContainsString('name="starts_on" value="2026-02-31"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
    }

    /**
     * The optional end, which is the whole reason a range is two fields: the
     * `required` flag already says it, so nothing has to invent an encoding
     * that puts two days behind one control.
     */
    #[Test]
    public function an_optional_end_is_a_second_field_not_a_second_value(): void
    {
        $start = ContentField::date('starts_on', 'Починається', '2026-09-11', true);
        $end = ContentField::date('ends_on', 'Завершується', '', false);

        self::assertTrue($start->required);
        self::assertFalse($end->required);
        self::assertNotSame($start->name, $end->name, 'two names, so one edit cannot write both ends');
    }
}
