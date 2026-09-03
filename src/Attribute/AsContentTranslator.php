<?php

declare(strict_types=1);

namespace Semitexa\Cms\Attribute;

use Attribute;

/**
 * Marks the class that keeps one kind of record's languages in step.
 *
 * Tenant-scoped like the other content surfaces: a translator belongs to a site,
 * and a queue drained under the wrong tenant would look at rows it cannot read.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsContentTranslator
{
    public function __construct(
        public string $tenant = '',
    ) {
    }
}
