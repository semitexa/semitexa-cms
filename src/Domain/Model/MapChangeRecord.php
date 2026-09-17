<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * One difference between two snapshots of a map.
 *
 * Typed rather than a formatted string, because the tests assert what CHANGED
 * and the console prints how it reads — and a diff whose only representation is
 * its own sentence can be asserted only by matching prose.
 */
final readonly class MapChangeRecord
{
    public function __construct(
        public MapChangeKind $kind,
        public string $ref,
        public string $message,
    ) {}

    /** @return array{kind: string, ref: string, message: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'ref' => $this->ref, 'message' => $this->message];
    }
}
