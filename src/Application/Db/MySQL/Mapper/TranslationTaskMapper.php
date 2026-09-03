<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Db\MySQL\Mapper;

use Semitexa\Cms\Application\Db\MySQL\Model\TranslationTaskResource;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;

/**
 * The task is its own domain model — it carries bookkeeping, not meaning — so
 * this mapper is the identity the ORM insists on rather than a translation
 * between two shapes.
 */
#[AsMapper(resourceModel: TranslationTaskResource::class, domainModel: TranslationTaskResource::class)]
final class TranslationTaskMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof TranslationTaskResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof TranslationTaskResource || throw new \InvalidArgumentException('Unexpected domain model.');

        return $domainModel;
    }
}
