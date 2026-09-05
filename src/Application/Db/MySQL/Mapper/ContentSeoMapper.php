<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Db\MySQL\Mapper;

use Semitexa\Cms\Application\Db\MySQL\Model\ContentSeoResource;
use Semitexa\Cms\Domain\Model\ContentSeo;
use Semitexa\Cms\Domain\Model\ContentSeoRecord;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;

/**
 * The bridge between the MySQL row and the record the CMS works in.
 *
 * The two differ in more than naming: the row keeps the claimed-field list as a
 * JSON string and every meta field flat, because that is what a column can
 * hold, while the record carries a {@see ContentSeo} value object with the list
 * as an array. Another database's resource would spell all of that differently
 * and still map onto the same record.
 */
#[AsMapper(resourceModel: ContentSeoResource::class, domainModel: ContentSeoRecord::class)]
final class ContentSeoMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ContentSeoResource || throw new \InvalidArgumentException('Unexpected resource model.');

        $authored = json_decode($resourceModel->authoredJson, true);

        return new ContentSeoRecord(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenantId,
            editorId: $resourceModel->editorId,
            seo: new ContentSeo(
                ref: $resourceModel->ref,
                title: $resourceModel->title,
                description: $resourceModel->description,
                ogTitle: $resourceModel->ogTitle,
                ogDescription: $resourceModel->ogDescription,
                ogImage: $resourceModel->ogImage,
                jsonLd: $resourceModel->jsonLd,
                canonical: $resourceModel->canonical,
                robots: $resourceModel->robots,
                // A hand-edited row must not take the store down with it.
                authored: is_array($authored) ? array_values(array_filter($authored, 'is_string')) : [],
                sourceHash: $resourceModel->sourceHash,
            ),
            dueAt: $resourceModel->dueAt,
            attempts: $resourceModel->attempts,
            lastError: $resourceModel->lastError,
            createdAt: $resourceModel->createdAt,
            updatedAt: $resourceModel->updatedAt,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof ContentSeoRecord || throw new \InvalidArgumentException('Unexpected domain model.');

        $now = new \DateTimeImmutable();
        $seo = $domainModel->getSeo();

        return new ContentSeoResource(
            id: $domainModel->getId(),
            tenantId: $domainModel->getTenantId(),
            ref: $seo->ref,
            editorId: $domainModel->getEditorId(),
            title: $seo->title,
            description: $seo->description,
            ogTitle: $seo->ogTitle,
            ogDescription: $seo->ogDescription,
            ogImage: $seo->ogImage,
            jsonLd: $seo->jsonLd,
            canonical: $seo->canonical,
            robots: $seo->robots,
            authoredJson: (string) json_encode($seo->authored, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE),
            sourceHash: $seo->sourceHash,
            dueAt: $domainModel->getDueAt(),
            attempts: $domainModel->getAttempts(),
            lastError: $domainModel->getLastError(),
            createdAt: $domainModel->getCreatedAt() ?? $now,
            updatedAt: $domainModel->getUpdatedAt() ?? $now,
        );
    }
}
