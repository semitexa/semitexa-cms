<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Handler\PayloadHandler;

use Semitexa\Cms\Application\Payload\Request\ContentImagePayload;
use Semitexa\Cms\Application\Service\ContentImageCollection;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Media\Domain\Contract\MediaServiceInterface;

/**
 * Sends a reader to wherever the asset currently lives.
 *
 * A redirect where one is possible: the storage may be a CDN, and streaming
 * the bytes through a Swoole worker would hold that worker for the length of a
 * photograph's download while achieving nothing the redirect does not.
 *
 * But a redirect needs somewhere to point, and the local driver — what a fresh
 * install runs — publishes no public URL for its objects unless the deployment
 * sets STORAGE_LOCAL_PUBLIC_URL. There the application is the only thing that
 * can reach the file, so it reads and serves it. Without that branch every
 * image an author uploads is a 404 the moment it is dropped in.
 *
 * The variant is asked for first and the original is the fallback, because a
 * freshly uploaded image has not been transformed yet — the queue does that.
 * Serving the original in the meantime is what stops a just-saved article
 * showing broken pictures for however long the worker takes.
 *
 * This route is public and takes an id, so it says out loud which collection it
 * serves. Without that an id is a bearer token for the whole media store: every
 * collection an installation has — a private one, someone's documents — is
 * reachable through the address meant for article pictures.
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

        if ($assetId === '' || !$this->media->belongsToCollection($assetId, ContentImageCollection::KEY)) {
            return $this->notFound($resource);
        }

        $url = $this->media->getUrl($assetId, self::VARIANT);

        if ($url === '') {
            $object = $this->media->readObject($assetId, self::VARIANT);

            // The row is there but the object is not — deleted underneath us,
            // or a storage that no longer holds it. There is no image at this
            // address, and saying more would describe our storage to whoever
            // asked.
            if ($object === null) {
                return $this->notFound($resource);
            }

            return $resource
                ->setStatusCode(HttpStatus::Ok->value)
                ->setHeader('Content-Type', $object->mimeType)
                // The type is the collection's, not the uploader's — but this
                // response carries bytes a stranger chose, and a browser that
                // sniffs its way to something else would be executing them.
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setHeader('Cache-Control', 'public, max-age=0, must-revalidate')
                ->setContent($object->contents);
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

    private function notFound(ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setStatusCode(HttpStatus::NotFound->value)
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setContent('Not Found');
    }
}
