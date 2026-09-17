<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/** What happened to one place between two snapshots of a map. */
enum MapChangeKind: string
{
    /** On the map before, not on it now. The museum's missing branch, from above. */
    case Gone = 'gone';
    case New = 'new';
    case Renamed = 'renamed';
    case Moved = 'moved';
    /** It still exists, and whether the console can open it changed. */
    case VerdictChanged = 'verdict_changed';

    /**
     * The place still opens, and opens something ELSE.
     *
     * A move is visible in the tree; a re-pointed editor or collection source
     * is not. Both sides stay Reachable, so the verdict does not move either —
     * which made `--compare` report a clean map while every link under that
     * place had quietly changed what it leads to.
     */
    case SourceChanged = 'source_changed';
    /** Every place of a site is gone, because the site is. */
    case SiteGone = 'site_gone';
}
