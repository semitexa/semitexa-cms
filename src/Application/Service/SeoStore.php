<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Application\Db\MySQL\Model\ContentSeoResource;
use Semitexa\Cms\Domain\Model\ContentSeo;
use Semitexa\Cms\Domain\Model\ContentSeoRecord;
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
 * Where a page's metadata lives, and the debounce in front of writing it.
 *
 * People edit in bursts — save, reread, fix a word, save again. Generating on
 * the write itself spends a model call per correction and the reader only ever
 * sees whichever version happened to be last. So a save does not generate: it
 * pushes a deadline out, and only text that has been still for the whole window
 * is worth describing.
 *
 * {@see settle()} is what keeps it cheap over time. Metadata already written
 * from exactly this text owes nothing, so a re-save of unchanged content costs
 * no call, and a word edited back to what it was cancels the pending work
 * rather than paying for it twice.
 */
#[AsService]
final class SeoStore
{
    use OrmBackedStore;

    /** How long a page must sit unedited before its metadata is worth rewriting. */
    public const DEBOUNCE_MINUTES = 10;

    /** Enough failures to stop trying — a provider outage should not spin forever. */
    public const MAX_ATTEMPTS = 5;

    /** Backoff between attempts. */
    private const RETRY_MINUTES = 30;

    #[InjectAsReadonly]
    protected OrmManager $orm;

    /** Per-site: a drain running as one tenant must not see or settle another's pages. */
    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantContextStore;

    /** The metadata for a page — an empty record when it has none yet. */
    public function get(string $ref): ContentSeo
    {
        return $this->find(trim($ref))?->getSeo() ?? new ContentSeo(ref: trim($ref));
    }

    /**
     * Park a page, or push its deadline out.
     *
     * Idempotent by content: an unchanged save that was already described owes
     * nothing, a changed one restarts the window and clears the failure count.
     */
    public function touch(string $ref, string $editorId, string $sourceHash, ?int $delayMinutes = null): void
    {
        $ref = trim($ref);
        if ($ref === '' || $sourceHash === '') {
            return;
        }

        $now = new \DateTimeImmutable();
        $due = $now->modify('+' . max(0, $delayMinutes ?? self::DEBOUNCE_MINUTES) . ' minutes');
        $row = $this->find($ref);

        if ($row === null) {
            $this->repository()->insert($this->newRecord($ref, $editorId, $due, $now));

            return;
        }

        // Already written from exactly this text: nothing is owed. This is the
        // case that makes a burst of corrections cheap — an editor who changes
        // a word and changes it back cancels the work instead of buying it.
        $settled = $row->getSeo()->sourceHash !== '' && $row->getSeo()->sourceHash === $sourceHash;

        $this->repository()->update($row->with([
            'editorId' => $editorId,
            'dueAt' => $settled ? null : $due,
            'attempts' => $settled ? $row->getAttempts() : 0,
            'lastError' => $settled ? $row->getLastError() : null,
        ]));
    }

    /**
     * Pages whose window has closed, oldest deadline first.
     *
     * @return list<ContentSeoRecord>
     */
    public function due(int $limit = 10, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        /** @var list<ContentSeoRecord> $rows */
        $rows = $this->repository()->query()
            ->whereNotNull(ContentSeoResource::column('dueAt'))
            ->where(ContentSeoResource::column('dueAt'), Operator::LessThanOrEquals, $now->format('Y-m-d H:i:s'))
            ->where(ContentSeoResource::column('attempts'), Operator::LessThan, self::MAX_ATTEMPTS)
            ->orderBy(ContentSeoResource::column('dueAt'), Direction::Asc)
            ->limit(max(1, $limit))
            ->fetchAllAs(ContentSeoRecord::class, $this->mapperRegistry());

        return $rows;
    }

    /**
     * Store what was written and clear the deadline.
     */
    public function settle(ContentSeo $seo, string $editorId, string $currentHash): void
    {
        $this->upsert($seo, $editorId, [
            'sourceHash' => $seo->sourceHash,
            // Cleared only when the metadata describes the text that is there
            // NOW. A save that landed mid-generation leaves the row due, so the
            // next drain describes what the page actually says rather than
            // settling against a version nobody will see.
            'dueAt' => $seo->sourceHash === $currentHash ? null : new \DateTimeImmutable(),
            'attempts' => 0,
            'lastError' => null,
        ]);
    }

    /**
     * Record what a person typed. Never touches the deadline: their words are
     * the answer, not a reason to go and ask for another one.
     *
     * @param array<string, string> $values
     */
    public function saveAuthored(string $ref, string $editorId, array $values): ContentSeo
    {
        $seo = $this->get($ref)->withAuthored($values);
        $this->upsert($seo, $editorId, []);

        return $seo;
    }

    /**
     * @param array<string, mixed> $schedule columns beyond the metadata itself
     */
    private function upsert(ContentSeo $seo, string $editorId, array $schedule): void
    {
        $ref = trim($seo->ref);
        $row = $this->find($ref);
        $next = ($row ?? $this->newRecord($ref, $editorId, null, new \DateTimeImmutable()))
            ->with(['editorId' => $editorId, 'seo' => $seo] + $schedule);

        $row === null ? $this->repository()->insert($next) : $this->repository()->update($next);
    }

    /** A failed attempt: back off, and give up after {@see MAX_ATTEMPTS}. */
    public function fail(string $ref, string $error): void
    {
        $row = $this->find(trim($ref));
        if ($row === null) {
            return;
        }

        $attempts = $row->getAttempts() + 1;
        $this->repository()->update($row->with([
            'attempts' => $attempts,
            'lastError' => mb_substr($error, 0, 500),
            'dueAt' => $attempts >= self::MAX_ATTEMPTS
                ? null
                : (new \DateTimeImmutable())->modify('+' . self::RETRY_MINUTES . ' minutes'),
        ]));
    }

    /** The page is gone; keeping the row would retry forever against nothing. */
    public function forget(string $ref): void
    {
        $row = $this->find(trim($ref));
        if ($row !== null) {
            $this->repository()->delete($row);
        }
    }

    private function find(string $ref): ?ContentSeoRecord
    {
        /** @var ContentSeoRecord|null $row */
        $row = $this->repository()->query()
            ->where(ContentSeoResource::column('ref'), Operator::Equals, $ref)
            ->fetchOneAs(ContentSeoRecord::class, $this->mapperRegistry());

        return $row;
    }

    private function newRecord(string $ref, string $editorId, ?\DateTimeImmutable $due, \DateTimeImmutable $now): ContentSeoRecord
    {
        return new ContentSeoRecord(
            id: Uuid7::generate(),
            tenantId: $this->currentTenantId(),
            editorId: $editorId,
            seo: new ContentSeo(ref: $ref),
            dueAt: $due,
            attempts: 0,
            lastError: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function repository(): DomainRepository
    {
        return $this->domainRepository(ContentSeoResource::class, ContentSeoRecord::class)->forTenant($this->currentTenantId());
    }

    private function currentTenantId(): string
    {
        $context = isset($this->tenantContextStore) ? $this->tenantContextStore->tryGet() : null;

        return TenantContextAccess::tenantIdOrDefault($context);
    }
}
