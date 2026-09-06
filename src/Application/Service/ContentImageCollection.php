<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Media\Attribute\AsMediaCollectionProvider;
use Semitexa\Media\Domain\Contract\MediaCollectionProviderInterface;

/**
 * Where an image dropped into an article body goes.
 *
 * The policy is the upload's real gate: the ingest checks the mime type and the
 * size against this, so widening it widens what a console user can put on the
 * public site. Kept to the three formats a browser will render everywhere, and
 * a size a museum's photograph fits in without letting someone park a video in
 * an article.
 *
 * Public by default because the images ARE the article — a private default
 * would make every picture a broken box for the reader.
 */
#[AsMediaCollectionProvider]
final class ContentImageCollection implements MediaCollectionProviderInterface
{
    public const KEY = 'cms:content';

    public function collections(): array
    {
        return [[
            'collectionKey' => self::KEY,
            'mediaKind' => 'image',
            'visibilityDefault' => 'public',
            'quotaBucket' => 'cms',
            'allowedMimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
            'maxOriginalBytes' => 12 * 1024 * 1024,
            'transformPresets' => [
                // One variant, wide enough for a full-width article image on a
                // dense screen. More sizes are a later slice with the markup
                // that would use them — a variant nothing references is just
                // work the queue does for nobody.
                'content' => [
                    'mode' => 'fit',
                    'width' => 1600,
                    'height' => null,
                    'format' => 'webp',
                    'quality' => 82,
                ],
            ],
        ]];
    }
}
