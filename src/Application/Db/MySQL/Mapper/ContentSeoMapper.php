<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Db\MySQL\Mapper;

use Semitexa\Cms\Application\Db\MySQL\Model\ContentSeoResource;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;

/**
 * Self-mapping mapper for {@see ContentSeoResource}, matching
 * {@see TranslationTaskMapper} beside it and the summary mapper in semitexa-os.
 *
 * The row carries a page's metadata AND the schedule for rewriting it, and the
 * ORM's write path maps domain → source, so a mapper onto a narrower domain
 * model could not rebuild the id, tenant, deadline or attempt count. The ORM's
 * own MissingMapperException asks for exactly this shape by name.
 *
 * NOTE: `semitexa.noOpMapper` rejects that shape. The two are in direct
 * contradiction and every bookkeeping table in the framework is on this side of
 * it; reported rather than worked around.
 */
#[AsMapper(resourceModel: ContentSeoResource::class, domainModel: ContentSeoResource::class)]
final class ContentSeoMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ContentSeoResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return $resourceModel;
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof ContentSeoResource || throw new \InvalidArgumentException('Unexpected domain model.');

        return $domainModel;
    }
}
