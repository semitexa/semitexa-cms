<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * What is wrong with one place on the map, in the map's own terms.
 *
 * Every value names a way the console can OFFER a place and then fail to open
 * it — which is the shape the first report of this took: a section of the map
 * gone, and a page that would not open, both found by the person whose site it
 * is rather than by anything that ran.
 */
enum PlaceVerdict: string
{
    /** Opens. Nothing to say. */
    case Reachable = 'reachable';

    /**
     * The place names an editor no module registers.
     *
     * A place is offered, clicked, and nothing answers — the module that used
     * to declare that editor id was renamed, removed, or is scoped to another
     * tenant.
     */
    case EditorMissing = 'editor_missing';

    /**
     * The editor resolves and its `load($ref)` returns null: the ROW is gone.
     *
     * The map still says the page is there. This is the museum's symptom, and
     * the reason a row count proves nothing — the count was right and the page
     * was not there.
     */
    case RecordMissing = 'record_missing';

    /** A collection whose source nothing lists: it opens an empty grid. */
    case SourceMissing = 'source_missing';

    /** Its parent is not on the map, so nothing links to it. */
    case OrphanParent = 'orphan_parent';

    /**
     * The place exists, its parent exists, and the SITE cannot reach it.
     *
     * OrphanParent only answers "is there a parent record": a place hanging
     * from nothing, or two places naming each other, satisfied that and were
     * reported Reachable — so the console offered a link nobody could arrive
     * at by navigating. Reachability is a walk from the root, not a look at
     * one edge.
     */
    case Unreachable = 'unreachable';

    /** Two places claim the same ref, so one of them is unreachable by ref. */
    case DuplicateRef = 'duplicate_ref';

    /** True for everything except {@see self::Reachable}. */
    public function isProblem(): bool
    {
        return $this !== self::Reachable;
    }
}
