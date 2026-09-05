<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Domain\Model\TranslationTask;

/**
 * The copy-with helper carries the three fields that must be settable to NULL —
 * settling clears the deadline, a fresh edit clears the error. A `??` fallback
 * would silently keep the old value and leave a settled row looking due forever.
 *
 * On the business model, not the row: the rule is about what the queue means by
 * settled, which is the package's decision rather than a column's.
 */
final class TranslationTaskTest extends TestCase
{
    private function task(): TranslationTask
    {
        return new TranslationTask(
            id: 'task-1',
            tenantId: 'regmus',
            ref: 'regmus:page:3',
            translatorId: 'regmus:page',
            sourceHash: 'aaa',
            translatedHash: null,
            dueAt: new \DateTimeImmutable('2026-09-02 15:00:00'),
            attempts: 2,
            lastError: 'provider timed out',
            createdAt: new \DateTimeImmutable('2026-09-01 10:00:00'),
            updatedAt: new \DateTimeImmutable('2026-09-02 14:50:00'),
        );
    }

    #[Test]
    public function settling_can_clear_the_deadline(): void
    {
        $settled = $this->task()->with(['translatedHash' => 'aaa', 'dueAt' => null, 'lastError' => null, 'attempts' => 0]);

        self::assertNull($settled->getDueAt(), 'A settled row must owe nothing.');
        self::assertNull($settled->getLastError());
        self::assertSame('aaa', $settled->getTranslatedHash());
        self::assertSame(0, $settled->getAttempts());
    }

    #[Test]
    public function identity_and_history_survive_the_copy(): void
    {
        $updated = $this->task()->with(['sourceHash' => 'bbb']);

        self::assertSame('task-1', $updated->getId());
        self::assertSame('regmus:page:3', $updated->getRef());
        self::assertSame('regmus', $updated->getTenantId());
        self::assertEquals(new \DateTimeImmutable('2026-09-01 10:00:00'), $updated->getCreatedAt());
    }

    #[Test]
    public function untouched_fields_keep_their_values(): void
    {
        $updated = $this->task()->with(['attempts' => 3]);

        self::assertSame('aaa', $updated->getSourceHash());
        self::assertSame('provider timed out', $updated->getLastError());
        self::assertEquals(new \DateTimeImmutable('2026-09-02 15:00:00'), $updated->getDueAt());
    }

    #[Test]
    public function a_task_knows_whether_its_text_is_the_translated_one(): void
    {
        self::assertFalse($this->task()->isSettled(), 'never translated is not settled');
        self::assertTrue($this->task()->with(['translatedHash' => 'aaa'])->isSettled());
        self::assertFalse(
            $this->task()->with(['translatedHash' => 'aaa', 'sourceHash' => 'bbb'])->isSettled(),
            'edited since the translation',
        );
    }

    #[Test]
    public function every_copy_is_freshly_stamped(): void
    {
        $updated = $this->task()->with([]);

        self::assertGreaterThan($this->task()->getUpdatedAt(), $updated->getUpdatedAt());
    }
}
