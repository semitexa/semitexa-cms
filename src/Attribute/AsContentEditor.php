<?php

declare(strict_types=1);

namespace Semitexa\Cms\Attribute;

use Attribute;

/**
 * Marks a class as the editor for one kind of record.
 *
 * Discovered by attribute for the same reason maps are: an install has several,
 * one per record kind and per tenant, and a service contract would let the last
 * one discovered silently win.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsContentEditor
{
    public function __construct(
        /** Tenant this editor belongs to; '' when the install serves a single site. */
        public string $tenant = '',
    ) {
    }
}
