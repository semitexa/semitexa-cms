<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Domain\Contract\OsContentSurfaceInterface;

/**
 * Take one record off the site.
 *
 * Two refs, because they answer different questions: `ref` is the record, and
 * `collection` is the list that authorises the removal and that the author
 * returns to. Deriving the second from the first would mean trusting the caller
 * about which collection a record belongs to.
 */
#[AsProtectedPayload(
    path: '/os/app/cms/delete',
    methods: ['POST'],
    responseWith: ResourceResponse::class,
    consumes: ['application/x-www-form-urlencoded'],
    produces: ['text/html'],
)]
final class ContentDeletePayload implements ValidatablePayloadInterface, OsContentSurfaceInterface
{
    private string $ref = '';
    private string $collection = '';

    /**
     * Unset on the first post: the console answers with a page naming what is
     * about to go, and only a second, deliberate post carries it.
     */
    private string $confirm = '';

    /** @return array<string, list<string>> */
    public function validate(): array
    {
        $errors = [];
        if (trim($this->ref) === '') {
            $errors['ref'] = ['Which record should be removed?'];
        }
        if (trim($this->collection) === '') {
            $errors['collection'] = ['Which list is it in?'];
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

    public function getCollection(): string
    {
        return $this->collection;
    }

    public function setCollection(string $collection): void
    {
        $this->collection = $collection;
    }

    public function isConfirmed(): bool
    {
        return trim($this->confirm) !== '';
    }

    public function setConfirm(string $confirm): void
    {
        $this->confirm = $confirm;
    }
}
