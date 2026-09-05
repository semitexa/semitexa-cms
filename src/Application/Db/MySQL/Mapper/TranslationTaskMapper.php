<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Db\MySQL\Mapper;

use Semitexa\Cms\Application\Db\MySQL\Model\TranslationTaskResource;
use Semitexa\Cms\Domain\Model\TranslationTask;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;

/**
 * The bridge between the MySQL row and the task the queue reasons about.
 *
 * The row spells its fields as snake_case columns with MySQL types; the task
 * spells them as the package's own names. Keeping them apart is what lets a
 * different database carry the same queue.
 */
#[AsMapper(resourceModel: TranslationTaskResource::class, domainModel: TranslationTask::class)]
final class TranslationTaskMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof TranslationTaskResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new TranslationTask(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenantId,
            ref: $resourceModel->ref,
            translatorId: $resourceModel->translatorId,
            sourceHash: $resourceModel->sourceHash,
            translatedHash: $resourceModel->translatedHash,
            dueAt: $resourceModel->dueAt,
            attempts: $resourceModel->attempts,
            lastError: $resourceModel->lastError,
            createdAt: $resourceModel->createdAt,
            updatedAt: $resourceModel->updatedAt,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof TranslationTask || throw new \InvalidArgumentException('Unexpected domain model.');

        $now = new \DateTimeImmutable();

        return new TranslationTaskResource(
            id: $domainModel->getId(),
            tenantId: $domainModel->getTenantId(),
            ref: $domainModel->getRef(),
            translatorId: $domainModel->getTranslatorId(),
            sourceHash: $domainModel->getSourceHash(),
            translatedHash: $domainModel->getTranslatedHash(),
            dueAt: $domainModel->getDueAt(),
            attempts: $domainModel->getAttempts(),
            lastError: $domainModel->getLastError(),
            createdAt: $domainModel->getCreatedAt() ?? $now,
            updatedAt: $domainModel->getUpdatedAt() ?? $now,
        );
    }
}
