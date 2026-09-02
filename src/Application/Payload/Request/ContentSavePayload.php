<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;

/**
 * Submitted edits for one record.
 *
 * The values arrive as a form post rather than typed properties because the
 * fields are the module's, not this package's: an editor declares them and this
 * route carries them back unread.
 */
#[AsProtectedPayload(
    path: '/os/app/cms/save',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/x-www-form-urlencoded'],
    produces: ['text/html'],
)]
final class ContentSavePayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    private string $ref = '';

    /**
     * The fields belong to the module's editor, not to this payload, so they
     * are read from the raw body rather than declared here. The framework hands
     * the request over through this convention.
     */
    private ?Request $httpRequest = null;

    public function setHttpRequest(Request $httpRequest): void
    {
        $this->httpRequest = $httpRequest;
    }

    /** @return array<string, string> */
    public function submittedValues(): array
    {
        $values = [];

        foreach ($this->httpRequest->post ?? [] as $key => $value) {
            if (is_string($key) && is_scalar($value) && !in_array($key, ['ref', '_csrf', 'csrf_token'], true)) {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        return $this->ref === '' ? ['ref' => ['A record to save is required.']] : [];
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
