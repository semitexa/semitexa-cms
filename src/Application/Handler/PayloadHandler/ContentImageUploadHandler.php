<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Handler\PayloadHandler;

use Semitexa\Cms\Application\Payload\Request\ContentImageUploadPayload;
use Semitexa\Cms\Application\Service\ContentImageCollection;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Media\Domain\Contract\MediaServiceInterface;

/**
 * Takes an image the author dropped into the editor and gives back the URL the
 * article will carry.
 *
 * The URL returned is this application's, not the storage's — see
 * {@see \Semitexa\Cms\Application\Payload\Request\ContentImagePayload} for why.
 *
 * The client's declared mime type is passed to the ingest, which is the thing
 * that decides: the collection's allowed list is checked there, against the
 * bytes, and the ingest refuses what does not match. A filename or a
 * Content-Type header from a browser is a claim, not evidence.
 */
#[AsPayloadHandler(payload: ContentImageUploadPayload::class, resource: ResourceResponse::class)]
final class ContentImageUploadHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected MediaServiceInterface $media;

    #[InjectAsMutable]
    protected AuthContextInterface $auth;

    public function handle(ContentImageUploadPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $file = $payload->file();

        if ($file === null || !$file->isOk()) {
            return $this->json($resource, HttpStatus::BadRequest->value, [
                'error' => $file?->errorMessage() ?? 'Не надіслано файл.',
            ]);
        }

        try {
            $reference = $this->media->ingestUploadedImage(
                contents: $file->getContents(),
                originalName: $file->clientFilename,
                mimeType: $file->clientMimeType,
                collectionKey: ContentImageCollection::KEY,
                createdBy: $this->currentUserId(),
            );
        } catch (\InvalidArgumentException $e) {
            // The collection refused it — wrong format, too large, over quota.
            // The author is standing there waiting, so say which.
            return $this->json($resource, HttpStatus::UnprocessableEntity->value, ['error' => $e->getMessage()]);
        } catch (\Throwable) {
            return $this->json($resource, HttpStatus::InternalServerError->value, [
                'error' => 'Не вдалося зберегти зображення.',
            ]);
        }

        return $this->json($resource, HttpStatus::Ok->value, [
            'url' => '/os/app/cms/media/' . rawurlencode($reference->assetId),
        ]);
    }

    /** Recorded as the asset's author; null on a surface with no signed-in user. */
    private function currentUserId(): ?string
    {
        return $this->auth->getUser()?->getId();
    }

    /** @param array<string, mixed> $body */
    private function json(ResourceResponse $resource, int $status, array $body): ResourceResponse
    {
        return $resource
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setContent((string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
