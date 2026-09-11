<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Cms\Domain\Model\ContentSeo;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Request;
use Semitexa\Os\Domain\Contract\OsContentSurfaceInterface;

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
final class ContentSavePayload implements ValidatablePayloadInterface, OsContentSurfaceInterface
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

    /**
     * The module's own fields.
     *
     * `seo` is named in the exclusion list rather than left to be filtered out
     * by the is_scalar test it would fail anyway: a module must never receive a
     * field it did not declare, and relying on the SHAPE of the metadata group
     * to keep it out would stop working the day it carries a single value.
     *
     * @return array<string, string>
     */
    public function submittedValues(): array
    {
        $values = [];

        foreach ($this->httpRequest->post ?? [] as $key => $value) {
            if (is_string($key) && is_scalar($value) && !in_array($key, ['ref', '_csrf', 'csrf_token', 'seo'], true)) {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * The page's metadata, which the CMS owns rather than the module.
     *
     * Empty when the form carried none — an editor whose panel does not offer
     * the fields must not be read as an author clearing every one of them.
     *
     * @return array<string, string>
     */
    public function submittedSeo(): array
    {
        $group = $this->httpRequest->post['seo'] ?? null;

        if (!is_array($group)) {
            return [];
        }

        $values = [];

        foreach ($group as $key => $value) {
            // Only what the form actually offers. Every scalar key used to be
            // accepted, which let a post set a field the editor has no control
            // for — and setting one marks it authored, which stops generation
            // from ever touching it again.
            if (is_string($key) && in_array($key, ContentSeo::EDITOR_FIELDS, true) && is_scalar($value)) {
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
