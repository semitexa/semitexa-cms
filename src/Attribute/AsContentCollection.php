<?php

declare(strict_types=1);

namespace Semitexa\Cms\Attribute;

use Attribute;

/**
 * Marks a class as the source of rows for one kind of collection.
 *
 * Discovered by attribute, and tenant-scoped, for the same reasons maps and
 * editors are.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsContentCollection
{
    public function __construct(
        public string $tenant = '',
    ) {
    }
}
