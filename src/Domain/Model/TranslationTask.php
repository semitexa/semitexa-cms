<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * One record waiting to be translated, and what is known about the wait.
 *
 * The queue is bookkeeping, but bookkeeping is still business: whether a record
 * is owed a translation, how many times the attempt has failed, and whether the
 * text has moved since are decisions this package makes, not columns MySQL
 * happens to have. The resource beside it in Db/MySQL spells the same facts as
 * snake_case columns; another database would spell them differently and still
 * produce this.
 *
 * The three nullable fields matter more than the rest: settling clears the
 * deadline, a fresh edit clears the error, and a record that has never been
 * translated has no translated hash. Each must survive being set back to null.
 */
final readonly class TranslationTask
{
    public function __construct(
        private string $id,
        private ?string $tenantId,
        private string $ref,
        private string $translatorId,
        /** Fingerprint of the text as it stood when the record was parked. */
        private string $sourceHash,
        /** Fingerprint of the text last translated, or null when never. */
        private ?string $translatedHash = null,
        /** When the debounce window closes, or null when nothing is owed. */
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

    public function getRef(): string
    {
        return $this->ref;
    }

    public function getTranslatorId(): string
    {
        return $this->translatorId;
    }

    public function getSourceHash(): string
    {
        return $this->sourceHash;
    }

    public function getTranslatedHash(): ?string
    {
        return $this->translatedHash;
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

    /** True when the text on record is exactly what was last translated. */
    public function isSettled(): bool
    {
        return $this->translatedHash !== null && $this->translatedHash === $this->sourceHash;
    }

    /**
     * A copy with some fields replaced.
     *
     * `array_key_exists` rather than `??`: a `??` fallback would silently keep
     * the old value when a caller passes null on purpose, leaving a settled row
     * looking due forever.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            ref: $this->ref,
            translatorId: is_string($changes['translatorId'] ?? null) ? $changes['translatorId'] : $this->translatorId,
            sourceHash: is_string($changes['sourceHash'] ?? null) ? $changes['sourceHash'] : $this->sourceHash,
            translatedHash: array_key_exists('translatedHash', $changes)
                ? (is_string($changes['translatedHash']) ? $changes['translatedHash'] : null)
                : $this->translatedHash,
            dueAt: array_key_exists('dueAt', $changes)
                ? ($changes['dueAt'] instanceof \DateTimeImmutable ? $changes['dueAt'] : null)
                : $this->dueAt,
            attempts: is_int($changes['attempts'] ?? null) ? $changes['attempts'] : $this->attempts,
            lastError: array_key_exists('lastError', $changes)
                ? (is_string($changes['lastError']) ? $changes['lastError'] : null)
                : $this->lastError,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }
}
