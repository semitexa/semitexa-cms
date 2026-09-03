<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Db\MySQL\Model\TranslationTaskResource;

/**
 * The copy-with helper carries the three fields that must be settable to NULL —
 * settling clears `due_at`, a fresh edit clears `last_error`. A `??` fallback
 * would silently keep the old value and leave a settled row looking due
 * forever.
 */
final class TranslationTaskTest extends TestCase
{
    private function task(): TranslationTaskResource
    {
        return new TranslationTaskResource(
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

        self::assertNull($settled->dueAt, 'A settled row must owe nothing.');
        self::assertNull($settled->lastError);
        self::assertSame('aaa', $settled->translatedHash);
        self::assertSame(0, $settled->attempts);
    }

    #[Test]
    public function identity_and_history_survive_the_copy(): void
    {
        $updated = $this->task()->with(['sourceHash' => 'bbb']);

        self::assertSame('task-1', $updated->id);
        self::assertSame('regmus:page:3', $updated->ref);
        self::assertSame('regmus', $updated->tenantId);
        self::assertEquals(new \DateTimeImmutable('2026-09-01 10:00:00'), $updated->createdAt);
    }

    #[Test]
    public function untouched_fields_keep_their_values(): void
    {
        $updated = $this->task()->with(['attempts' => 3]);

        self::assertSame('aaa', $updated->sourceHash);
        self::assertSame('provider timed out', $updated->lastError);
        self::assertEquals(new \DateTimeImmutable('2026-09-02 15:00:00'), $updated->dueAt);
    }

    #[Test]
    public function every_copy_is_freshly_stamped(): void
    {
        $updated = $this->task()->with([]);

        self::assertGreaterThan($this->task()->updatedAt, $updated->updatedAt);
    }
}
