<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * A page's metadata as the CMS holds it: the meta itself, plus who owns the
 * page and when it is next due for a look.
 *
 * Separate from {@see ContentSeo} because they answer different questions.
 * ContentSeo is what a page says about itself — the thing the writer produces
 * and the editor shows. This is that value plus the bookkeeping around keeping
 * it current, which nothing outside the store needs to see.
 *
 * Separate from the MySQL resource because the resource is a table: snake_case
 * columns, MySqlType, indexes. Those are one database's shape, and the mapper
 * between them is what lets another database carry the same record — the way
 * semitexa-project-graph maps a SQLite resource onto its domain models.
 */
final readonly class ContentSeoRecord
{
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private string $editorId,
        private ContentSeo $seo,
        /** When the page is next due for a look, or null when nothing is owed. */
        private ?\DateTimeImmutable $dueAt = null,
        private int $attempts = 0,
        private ?string $lastError = null,
        private ?\DateTimeImmutable $createdAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getEditorId(): string
    {
        return $this->editorId;
    }

    /** What the page says about itself — the part anything outside the store cares about. */
    public function getSeo(): ContentSeo
    {
        return $this->seo;
    }

    public function getRef(): string
    {
        return $this->seo->ref;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $take = fn (string $key, mixed $current): mixed => array_key_exists($key, $changes) ? $changes[$key] : $current;

        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            editorId: $take('editorId', $this->editorId),
            seo: $take('seo', $this->seo),
            dueAt: $take('dueAt', $this->dueAt),
            attempts: $take('attempts', $this->attempts),
            lastError: $take('lastError', $this->lastError),
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }
}
