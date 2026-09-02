<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Contract;

use Semitexa\Cms\Domain\Model\Place;

/**
 * A module describing which of its records are places on the site's map.
 *
 * Implemented per site (a tenant's own module knows its structure); discovered
 * by the projector. Everything the interface asks for is cheap to compute — the
 * map is a dozen places, not a table dump — so an implementation may query for
 * counts and titles freely.
 */
interface SiteMapProviderInterface
{
    /** Stable identity of the site itself, e.g. 'regmus'. */
    public function siteRef(): string;

    /** What the site is called on the map. */
    public function siteTitle(): string;

    /**
     * The places, in the order they should appear. Parents may follow children;
     * the projector links edges once every place is known.
     *
     * @return iterable<Place>
     */
    public function places(): iterable;
}
