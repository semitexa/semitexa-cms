<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;

/**
 * A place on the map, opened in a console dialog.
 *
 * One route for both surfaces because the node already says which it is: a page
 * opens its editor, a collection its list. Two routes would mean the shell has
 * to know the difference before it asks, which is exactly what the map is for.
 */
#[AsProtectedPayload(
    path: '/os/app/cms',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    produces: ['text/html'],
)]
final class ContentEditorPayload implements ValidatablePayloadInterface, OsSurfacePayloadInterface
{
    /** The place, as the map names it: 'regmus:page:3' or 'regmus:events'. */
    private string $ref = '';

    /** Which page of a collection; ignored by an editor. */
    private int $page = 1;

    public function getPage(): int
    {
        return max(1, $this->page);
    }

    public function setPage(int|string $page): void
    {
        $this->page = max(1, (int) $page);
    }

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
