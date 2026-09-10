<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Domain\Contract\OsContentSurfaceInterface;

/**
 * Start a new item in a collection.
 *
 * POST because it writes. The ref names the COLLECTION, not the record — the
 * record does not exist yet and the module is the one that will name it.
 */
#[AsProtectedPayload(
    path: '/os/app/cms/create',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/x-www-form-urlencoded'],
    produces: ['text/html'],
)]
final class ContentCreatePayload implements ValidatablePayloadInterface, OsContentSurfaceInterface
{
    /** The collection to start an item in, as the map names it. */
    private string $ref = '';

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        $errors = [];
        if (trim($this->ref) === '') {
            $errors['ref'] = ['Which collection should the new item go in?'];
        }

        return $errors;
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function setRef(string $ref): void
    {
        $this->ref = $ref;
    }
}
