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
    /** Every place of a site is gone, because the site is. */
    case SiteGone = 'site_gone';
}
