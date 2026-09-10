<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Contract;

/**
 * A collection that can start a new item of its kind.
 *
 * Separate from {@see ContentCollectionInterface} on purpose: listing and
 * authoring are different rights, and a collection that only ever shows records
 * from elsewhere must be able to say so by not implementing this. The console
 * offers the affordance where the capability exists and nowhere else, so an
 * author is never shown a button that answers with a refusal.
 *
 * The MODULE mints the ref. The CMS has no idea how a site names its records —
 * a slug, an auto-increment, a UUID — and a ref invented here would be a ref
 * the module's own repository could not find again.
 */
interface ContentCreatorInterface
{
    /**
     * Create an empty record and return its ref.
     *
     * @param array<string, string> $filters the collection's own query vocabulary,
     *        parsed from the source. A collection filled from `?type=event` starts
     *        an EVENT — creating into a filtered list and getting something the
     *        list then refuses to show is the bug this argument exists to prevent.
     *
     * @throws \RuntimeException when the record cannot be created; the console
     *         shows the message, so write it for the author rather than the log.
     */
    public function create(array $filters): string;
}
