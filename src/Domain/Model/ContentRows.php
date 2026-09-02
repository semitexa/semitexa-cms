<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * A page of a collection, and enough to page through the rest.
 */
final readonly class ContentRows
{
    /**
     * @param list<ContentRow> $rows
     */
    public function __construct(
        public string $title,
        public array $rows,
        public int $total,
        public int $page = 1,
        public int $perPage = 25,
    ) {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages();
    }
}
