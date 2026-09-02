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
     * The work this site belongs to — the museum, the clinic, the school.
     *
     * The site is not the root of anything: it hangs off the organisation the
     * person works on, which hangs off the person. Returning null leaves the
     * site unanchored, which is only right for an install that serves one site
     * and nothing else.
     */
    public function workTitle(): ?string;

    /**
     * Resource keys whose changes can move this map — table names, unless a
     * resource declares its own #[ResourceKey].
     *
     * A skill that publishes an event or renames a page writes a row; this is
     * what tells the map that the row was one of ITS rows. Returning an empty
     * list means the map only ever changes when someone rebuilds it by hand,
     * which for a live site is a map that quietly goes out of date.
     *
     * @return list<string>
     */
    public function watches(): array;

    /**
     * The places, in the order they should appear. Parents may follow children;
     * the projector links edges once every place is known.
     *
     * @return iterable<Place>
     */
    public function places(): iterable;
}
