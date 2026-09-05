<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * An image referenced from stored article markup, addressed by asset id.
 *
 * This route is the reason article HTML never carries a storage URL. Where the
 * bytes actually live is a deployment's business — a local disk with no public
 * mount, an S3 endpoint, a CDN in front of either — and `MediaUrlGenerator`
 * answers differently for each, including with an empty string when a local
 * install has published nothing. Writing that answer into an article would
 * freeze one deployment's storage layout into content that outlives it.
 *
 * So the markup names the asset and this redirects. Move the storage and every
 * article still resolves.
 *
 * Public because the images are part of the published page. The asset id is the
 * capability: it is not guessable, and nothing here lists them. But a
 * capability that opens every collection an installation has is the wrong
 * capability, so the handler serves ContentImageCollection and refuses the
 * rest — an id from a private collection is a 404 here like any other.
 */
#[AsPublicPayload(
    path: '/os/app/cms/media/{assetId}',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['text/html'],
)]
final class ContentImagePayload implements ValidatablePayloadInterface
{
    private string $assetId = '';

    public function setAssetId(string $assetId): void
    {
        $this->assetId = $assetId;
    }

    public function getAssetId(): string
    {
        return $this->assetId;
    }

    public function validate(): array
    {
        return trim($this->assetId) === ''
            ? ['assetId' => ['Не вказано зображення.']]
            : [];
    }
}
