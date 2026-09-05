<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Http\UploadedFile;
use Semitexa\Core\Request;

/**
 * One image dropped into an article body.
 *
 * Multipart rather than form-urlencoded because it carries a file; the CSRF
 * token rides in the same body, as it does on the save route — this is the
 * console's own surface, not an API.
 */
#[AsProtectedPayload(
    path: '/os/app/cms/media',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['multipart/form-data'],
    produces: ['application/json'],
)]
final class ContentImageUploadPayload implements ValidatablePayloadInterface
{
    /** The field name Trix posts under; the client script sets it. */
    public const FIELD = 'file';

    private ?Request $httpRequest = null;

    public function setHttpRequest(Request $httpRequest): void
    {
        $this->httpRequest = $httpRequest;
    }

    public function file(): ?UploadedFile
    {
        return $this->httpRequest?->getFile(self::FIELD);
    }

    public function validate(): array
    {
        $file = $this->file();

        if ($file === null) {
            return [self::FIELD => ['Не надіслано файл.']];
        }

        if (!$file->isOk()) {
            return [self::FIELD => [$file->errorMessage()]];
        }

        return [];
    }
}
