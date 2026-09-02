<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * One pending translation, at most one per record.
 *
 * `due_at` NULL means settled — nothing owed, and no comparison matches it, so
 * a settled row can never come back as due. `translated_hash` remembers what
 * already shipped, which is what makes text edited and then edited back cost
 * nothing.
 */
#[FromTable(name: 'cms_translation_task')]
#[Index(columns: ['tenant_id', 'ref'], unique: true, name: 'uniq_cms_translation_task_ref')]
#[Index(columns: ['tenant_id', 'due_at'], name: 'idx_cms_translation_task_due')]
#[TenantScoped(strategy: 'same_storage', column: 'tenant_id')]
final readonly class TranslationTaskResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(name: 'tenant_id', type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenantId,

        #[Column(name: 'ref', type: MySqlType::Varchar, length: 191)]
        public string $ref,

        #[Column(name: 'translator_id', type: MySqlType::Varchar, length: 128)]
        public string $translatorId,

        #[Column(name: 'source_hash', type: MySqlType::Varchar, length: 64)]
        public string $sourceHash,

        #[Column(name: 'translated_hash', type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $translatedHash,

        #[Column(name: 'due_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $dueAt,

        #[Column(name: 'attempts', type: MySqlType::Int)]
        public int $attempts,

        #[Column(name: 'last_error', type: MySqlType::Varchar, length: 512, nullable: true)]
        public ?string $lastError,

        #[Column(name: 'created_at', type: MySqlType::Datetime)]
        public \DateTimeImmutable $createdAt,

        #[Column(name: 'updated_at', type: MySqlType::Datetime)]
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            ref: $this->ref,
            translatorId: $changes['translatorId'] ?? $this->translatorId,
            sourceHash: $changes['sourceHash'] ?? $this->sourceHash,
            translatedHash: array_key_exists('translatedHash', $changes) ? $changes['translatedHash'] : $this->translatedHash,
            dueAt: array_key_exists('dueAt', $changes) ? $changes['dueAt'] : $this->dueAt,
            attempts: $changes['attempts'] ?? $this->attempts,
            lastError: array_key_exists('lastError', $changes) ? $changes['lastError'] : $this->lastError,
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }
}
