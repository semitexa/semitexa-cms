<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Application\Db\MySQL\Model\TranslationTaskResource;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Application\Service\OrmBackedStore;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;

/**
 * The debounce between "an admin saved" and "translate it".
 *
 * People edit in bursts — save, reread, fix a word, save again — so translating
 * on the write itself spends a model call per keystroke-sized correction, and
 * the reader still only ever sees whichever version happened to be last. One row
 * per record instead, its deadline pushed out on every save; the drain sees only
 * text that has been still for the whole window.
 *
 * `translatedHash` is what keeps it cheap over time: a re-save of unchanged text
 * settles for nothing, and a word edited back to what it was cancels the work
 * rather than paying for it twice.
 */
#[AsService]
final class TranslationQueue
{
    use OrmBackedStore;

    /** How long a record must sit unedited before it is worth translating. */
    public const DEBOUNCE_MINUTES = 10;

    /** Enough failures to stop trying — a provider outage should not spin forever. */
    public const MAX_ATTEMPTS = 5;

    /** Backoff between attempts. */
    private const RETRY_MINUTES = 30;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    /**
     * The queue is per-site: a drain running as one tenant must not see, retry
     * or settle another's records.
     */
    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    /**
     * Park a record, or push its deadline out.
     *
     * Idempotent by content: an unchanged save settles, a changed one restarts
     * the window and clears the failure count, and text that returns to an
     * already-translated state needs nothing at all.
     */
    public function enqueue(string $ref, string $translatorId, string $sourceHash, ?int $delayMinutes = null): void
    {
        $ref = trim($ref);
        if ($ref === '' || $sourceHash === '') {
            return;
        }

        $now = new \DateTimeImmutable();
        $due = $now->modify('+' . max(0, $delayMinutes ?? self::DEBOUNCE_MINUTES) . ' minutes');
        $row = $this->find($ref);

        if ($row === null) {
            $this->repository()->insert(new TranslationTaskResource(
                id: Uuid7::generate(),
                tenantId: $this->currentTenantId(),
                ref: $ref,
                translatorId: $translatorId,
                sourceHash: $sourceHash,
                translatedHash: null,
                dueAt: $due,
                attempts: 0,
                lastError: null,
                createdAt: $now,
                updatedAt: $now,
            ));

            return;
        }

        $changed = $row->sourceHash !== $sourceHash;

        $this->repository()->update($row->with([
            'translatorId' => $translatorId,
            'sourceHash' => $sourceHash,
            // Already translated exactly this text: nothing is owed.
            'dueAt' => $row->translatedHash === $sourceHash ? null : $due,
            'attempts' => $changed ? 0 : $row->attempts,
            'lastError' => $changed ? null : $row->lastError,
        ]));
    }

    /**
     * Records whose window has closed, oldest deadline first.
     *
     * @return list<TranslationTaskResource>
     */
    public function due(int $limit = 10, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        /** @var list<TranslationTaskResource> $rows */
        $rows = $this->repository()->query()
            ->whereNotNull(TranslationTaskResource::column('dueAt'))
            ->where(TranslationTaskResource::column('dueAt'), Operator::LessThanOrEquals, $now->format('Y-m-d H:i:s'))
            ->where(TranslationTaskResource::column('attempts'), Operator::LessThan, self::MAX_ATTEMPTS)
            ->orderBy(TranslationTaskResource::column('dueAt'), Direction::Asc)
            ->limit(max(1, $limit))
            ->fetchAllAs(TranslationTaskResource::class, $this->mapperRegistry());

        return $rows;
    }

    /**
     * Record that this exact text is translated.
     *
     * Keyed on the hash, not the row: someone who edited again WHILE the
     * translation was in flight has already re-stamped it, and settling would
     * declare their newer text done when only the older one is.
     */
    public function settle(string $ref, string $translatedHash): void
    {
        $row = $this->find($ref);
        if ($row === null) {
            return;
        }

        $this->repository()->update($row->with([
            'translatedHash' => $translatedHash,
            'lastError' => null,
            'attempts' => 0,
            // Only settle when this IS the current text: an edit that landed
            // while the translation was in flight has already re-stamped the
            // row, and settling would declare the newer text done.
            'dueAt' => $row->sourceHash === $translatedHash ? null : $row->dueAt,
        ]));
    }

    public function fail(string $ref, string $error): void
    {
        $row = $this->find($ref);
        if ($row === null) {
            return;
        }

        $this->repository()->update($row->with([
            'attempts' => $row->attempts + 1,
            'lastError' => mb_substr($error, 0, 500),
            'dueAt' => (new \DateTimeImmutable())->modify('+' . self::RETRY_MINUTES . ' minutes'),
        ]));
    }

    public function forget(string $ref): void
    {
        $row = $this->find($ref);
        if ($row !== null) {
            $this->repository()->delete($row);
        }
    }

    public function find(string $ref): ?TranslationTaskResource
    {
        $row = $this->repository()->query()
            ->where(TranslationTaskResource::column('ref'), Operator::Equals, trim($ref))
            ->fetchOneAs(TranslationTaskResource::class, $this->mapperRegistry());

        return $row instanceof TranslationTaskResource ? $row : null;
    }

    private function repository(): DomainRepository
    {
        // Resource doubles as its own domain model: the task is bookkeeping, and
        // a second class to carry six fields would be ceremony.
        return $this->domainRepository(TranslationTaskResource::class)->forTenant($this->currentTenantId());
    }

    private function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
    }
}
