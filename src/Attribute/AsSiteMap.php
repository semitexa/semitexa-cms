<?php

declare(strict_types=1);

namespace Semitexa\Cms\Attribute;

use Attribute;

/**
 * Marks a class as the author of one site's map.
 *
 * Discovery is by attribute rather than by interface because an install has one
 * provider per tenant, not one implementation of a contract: binding them as a
 * service contract would mean the last one discovered wins and the other sites
 * silently lose their map.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsSiteMap
{
    public function __construct(
        /** Tenant this map belongs to; '' when the install serves a single site. */
        public string $tenant = '',
    ) {
    }
}
