<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Contract;

/**
 * A collection whose items an author may take off the site.
 *
 * Opt-in for the same reason {@see ContentCreatorInterface} is: a module that
 * lists records it does not own says so by not implementing this, and the
 * console then offers no button that would answer with a refusal.
 *
 * ARCHIVE OR DELETE IS THE MODULE'S CHOICE, and the console does not pretend
 * otherwise. The CMS is a shell — it never touches the table, so a promise that
 * the bytes are gone is one it cannot keep. What it promises the author is
 * exactly what it can deliver: the item stops being on the site. A module free
 * to soft-delete, tombstone or truly drop the row keeps that freedom, and the
 * confirmation the author reads says the true thing either way.
 */
interface ContentRemoverInterface
{
    /**
     * Take this record off the site.
     *
     * @throws \RuntimeException when it cannot be removed; the console shows the
     *         message, so write it for the author rather than the log.
     */
    public function remove(string $ref): void;
}
