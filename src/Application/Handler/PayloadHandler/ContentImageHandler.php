<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Handler\PayloadHandler;

use Semitexa\Cms\Application\Payload\Request\ContentImagePayload;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Media\Domain\Contract\MediaServiceInterface;

/**
 * Sends a reader to wherever the asset currently lives.
 *
 * A redirect rather than a proxy: the storage may be a CDN, and streaming the
 * bytes through a Swoole worker would hold that worker for the length of a
 * photograph's download while achieving nothing the redirect does not.
 *
 * The variant is asked for first and the original is the fallback, because a
 * freshly uploaded image has not been transformed yet — the queue does that.
 * Serving the original in the meantime is what stops a just-saved article
 * showing broken pictures for however long the worker takes.
 */
#[AsPayloadHandler(payload: ContentImagePayload::class, resource: ResourceResponse::class)]
final class ContentImageHandler implements TypedHandlerInterface
{
    /** The variant the article markup is sized for; see ContentImageCollection. */
    private const VARIANT = 'content';

    #[InjectAsReadonly]
    protected MediaServiceInterface $media;

    public function handle(ContentImagePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $assetId = trim($payload->getAssetId());

        $url = $assetId === '' ? '' : $this->media->getUrl($assetId, self::VARIANT);

        if ($url === '') {
            // Either the id names nothing, or the deployment has published no
            // public storage URL at all. Both are 404 to a reader: there is no
            // image at this address, and saying which would describe our
            // storage configuration to the internet.
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        return $resource
            ->setStatusCode(HttpStatus::Found->value)
            ->setHeader('Location', $url)
            // The asset id is immutable but where it points is not, so the
            // redirect itself must stay revalidated — a cached 302 would
            // outlive a storage move.
            ->setHeader('Cache-Control', 'public, max-age=0, must-revalidate')
            ->setContent('');
    }
}
