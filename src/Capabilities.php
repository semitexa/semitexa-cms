<?php

declare(strict_types=1);

namespace Semitexa\Cms;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog. Nothing reads it at
 * runtime.
 */
#[Capability(
    id: 'cms.site-map',
    summary: 'A site as a navigable map of places: pages that open an editor, collections that open a grid.',
    useWhen: 'Someone who did not build the site has to find and change its content.',
    avoidWhen: 'The site has three pages and a menu already shows all of them.',
    replaces: [
        'an admin menu hand-written per site, and rewritten whenever the structure changes',
        'a tree built by dumping a content table into the UI, where 40 exhibition texts sit beside the contacts page as equals',
    ],
    seeAlso: 'semitexa/weave',
)]
final class Capabilities
{
}
