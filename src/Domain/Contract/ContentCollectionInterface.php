<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Contract;

use Semitexa\Cms\Domain\Model\ContentRows;

/**
 * How a module lists the records behind a collection on the map.
 *
 * The map says a collection is filled from `regmus:pages?type=event`; this is
 * what turns that into rows. The query part is the module's own vocabulary —
 * the CMS neither parses nor understands it, it only carries it back.
 */
interface ContentCollectionInterface
{
    /** The part before the query, e.g. 'regmus:pages'. */
    public function sourceId(): string;

    /**
     * @param array<string, string> $filters Parsed from the source's query part.
     */
    public function rows(array $filters, int $page, int $perPage): ContentRows;
}
