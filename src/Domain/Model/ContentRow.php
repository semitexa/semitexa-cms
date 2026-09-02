<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * One line in a collection.
 *
 * Enough to recognise the record and open it, nothing more: a grid that tries
 * to show every column becomes a spreadsheet nobody reads. `meta` is the one or
 * two facts that tell two similar rows apart — a date, a section, a state.
 *
 * @phpstan-type MetaList list<string>
 */
final readonly class ContentRow
{
    /**
     * @param list<string> $meta
     */
    public function __construct(
        public string $ref,
        public string $title,
        public array $meta = [],
        public ?string $publicUrl = null,
    ) {
    }
}
