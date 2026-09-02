<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;

/**
 * The editor for one place on the map, rendered inside a console dialog.
 */
#[AsProtectedPayload(
    path: '/os/app/cms',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['text/html'],
)]
final class ContentEditorPayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    /** The record to edit, as the map names it: 'regmus:page:3'. */
    private string $ref = '';

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        return [];
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
