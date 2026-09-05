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
 * One page's metadata, and when it next needs looking at.
 *
 * The schedule lives on the same row as the value on purpose. Translation keeps
 * its queue in a separate table because the translations themselves live in the
 * module, so the queue has nothing else to hold; here the metadata is the CMS's
 * own, and splitting "the meta" from "the meta is stale" would be two tables
 * always read together, always written together, and able to disagree.
 *
 * `due_at` is the whole debounce: every save pushes it forward, so ten
 * corrections in a row cost one generation rather than ten. Null means nothing
 * is owed — either it has never been touched, or the generation settled.
 */
#[FromTable(name: 'cms_content_seo')]
#[Index(columns: ['tenant_id', 'ref'], unique: true, name: 'uniq_cms_content_seo_ref')]
#[Index(columns: ['due_at'], name: 'idx_cms_content_seo_due')]
#[TenantScoped(strategy: 'same_storage', column: 'tenant_id')]
final readonly class ContentSeoResource
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(name: 'tenant_id', type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenantId,

        #[Column(name: 'ref', type: MySqlType::Varchar, length: 191)]
        public string $ref,

        /** The editor that owns this ref — how the drain finds the page again. */
        #[Column(name: 'editor_id', type: MySqlType::Varchar, length: 128)]
        public string $editorId,

        #[Column(name: 'title', type: MySqlType::Varchar, length: 255)]
        public string $title,

        #[Column(name: 'description', type: MySqlType::Varchar, length: 512)]
        public string $description,

        #[Column(name: 'og_title', type: MySqlType::Varchar, length: 255)]
        public string $ogTitle,

        #[Column(name: 'og_description', type: MySqlType::Varchar, length: 512)]
        public string $ogDescription,

        #[Column(name: 'og_image', type: MySqlType::Varchar, length: 512)]
        public string $ogImage,

        #[Column(name: 'json_ld', type: MySqlType::LongText)]
        public string $jsonLd,

        #[Column(name: 'canonical', type: MySqlType::Varchar, length: 512)]
        public string $canonical,

        #[Column(name: 'robots', type: MySqlType::Varchar, length: 128)]
        public string $robots,

        /** Field names a person claimed, as a JSON list. */
        #[Column(name: 'authored_json', type: MySqlType::Varchar, length: 512)]
        public string $authoredJson,

        /** Fingerprint of the content the current metadata was written from. */
        #[Column(name: 'source_hash', type: MySqlType::Varchar, length: 64)]
        public string $sourceHash,

        /** When this page is next due for a look, or null when nothing is owed. */
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
    ) {}

    /**
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $take = fn (string $key, mixed $current): mixed => array_key_exists($key, $changes) ? $changes[$key] : $current;

        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            ref: $this->ref,
            editorId: $take('editorId', $this->editorId),
            title: $take('title', $this->title),
            description: $take('description', $this->description),
            ogTitle: $take('ogTitle', $this->ogTitle),
            ogDescription: $take('ogDescription', $this->ogDescription),
            ogImage: $take('ogImage', $this->ogImage),
            jsonLd: $take('jsonLd', $this->jsonLd),
            canonical: $take('canonical', $this->canonical),
            robots: $take('robots', $this->robots),
            authoredJson: $take('authoredJson', $this->authoredJson),
            sourceHash: $take('sourceHash', $this->sourceHash),
            dueAt: $take('dueAt', $this->dueAt),
            attempts: $take('attempts', $this->attempts),
            lastError: $take('lastError', $this->lastError),
            createdAt: $this->createdAt,
            updatedAt: new \DateTimeImmutable(),
        );
    }
}
