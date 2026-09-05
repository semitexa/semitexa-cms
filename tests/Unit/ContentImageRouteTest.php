<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Handler\PayloadHandler\ContentImageHandler;
use Semitexa\Cms\Application\Payload\Request\ContentImagePayload;
use Semitexa\Cms\Application\Service\ContentImageCollection;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Media\Domain\Contract\MediaServiceInterface;
use Semitexa\Media\Domain\Model\MediaAssetReference;
use Semitexa\Media\Domain\Model\MediaObjectContents;
use Semitexa\Storage\Value\StoredObjectDescriptor;

/**
 * The route article markup points its images at.
 *
 * It is public and it takes an id, which makes it the one place in this package
 * where a stranger names something in the media store and we answer. Two things
 * have to hold: it serves only the collection it exists for, and it produces the
 * image on a deployment whose storage publishes no URL of its own — which is
 * what a fresh install runs, and where the route used to 404 for everything.
 */
final class ContentImageRouteTest extends TestCase
{
    #[Test]
    public function an_asset_from_another_collection_is_not_found(): void
    {
        $media = new class extends FakeMediaService {
            public string $collectionKey = 'people:avatars';
        };

        $response = $this->handle($media, 'someone-elses-asset');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $media->urlsAskedFor, 'a foreign asset must not even be looked up');
        self::assertSame([], $media->objectsAskedFor);
    }

    #[Test]
    public function an_empty_id_is_not_found(): void
    {
        $media = new FakeMediaService();

        self::assertSame(404, $this->handle($media, '   ')->getStatusCode());
        self::assertSame([], $media->collectionChecks, 'nothing to check when there is no id');
    }

    /** Where storage publishes URLs, the reader is sent there and we move no bytes. */
    #[Test]
    public function a_public_storage_url_is_redirected_to(): void
    {
        $media = new FakeMediaService();
        $media->url = 'https://cdn.example.test/content/a.webp?v=1';

        $response = $this->handle($media, 'asset-1');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://cdn.example.test/content/a.webp?v=1', $response->toCoreResponse()->getHeaders()['Location']);
        self::assertSame('', $response->getContent());
        self::assertSame([], $media->objectsAskedFor);
    }

    /**
     * The local driver publishes no URL unless the deployment sets one. Serving
     * the bytes here is the difference between an author's uploaded picture
     * appearing and every one of them being a broken image.
     */
    #[Test]
    public function bytes_are_served_when_storage_has_no_public_url(): void
    {
        $media = new FakeMediaService();
        $media->url = '';
        $media->object = new MediaObjectContents('PNGBYTES', 'image/png');

        $response = $this->handle($media, 'asset-1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('PNGBYTES', $response->getContent());
        self::assertSame('image/png', $response->toCoreResponse()->getHeaders()['Content-Type']);
        self::assertSame('nosniff', $response->toCoreResponse()->getHeaders()['X-Content-Type-Options']);
        self::assertSame([['asset-1', 'content']], $media->objectsAskedFor);
    }

    /** The row is there, the object is not: still nothing to show. */
    #[Test]
    public function a_missing_object_is_not_found(): void
    {
        $media = new FakeMediaService();
        $media->url = '';
        $media->object = null;

        self::assertSame(404, $this->handle($media, 'asset-1')->getStatusCode());
    }

    private function handle(FakeMediaService $media, string $assetId): ResourceResponse
    {
        $handler = new ContentImageHandler();
        $property = new \ReflectionProperty(ContentImageHandler::class, 'media');
        $property->setValue($handler, $media);

        $payload = new ContentImagePayload();
        $payload->setAssetId($assetId);

        return $handler->handle($payload, new ResourceResponse());
    }
}

/**
 * Records what the handler asked for, so a test can say what it did NOT ask —
 * which is the whole point of the collection guard.
 */
class FakeMediaService implements MediaServiceInterface
{
    public string $collectionKey = ContentImageCollection::KEY;
    public string $url = '';
    public ?MediaObjectContents $object = null;

    /** @var list<string> */
    public array $collectionChecks = [];
    /** @var list<array{0: string, 1: ?string}> */
    public array $urlsAskedFor = [];
    /** @var list<array{0: string, 1: ?string}> */
    public array $objectsAskedFor = [];

    public function belongsToCollection(string $assetId, string $collectionKey): bool
    {
        $this->collectionChecks[] = $assetId;

        return $this->collectionKey === $collectionKey;
    }

    public function getUrl(string $assetId, ?string $variantKey = null): string
    {
        $this->urlsAskedFor[] = [$assetId, $variantKey];

        return $this->url;
    }

    public function readObject(string $assetId, ?string $variantKey = null): ?MediaObjectContents
    {
        $this->objectsAskedFor[] = [$assetId, $variantKey];

        return $this->object;
    }

    public function ingestUploadedImage(
        string $contents,
        string $originalName,
        string $mimeType,
        string $collectionKey,
        ?string $createdBy = null,
    ): MediaAssetReference {
        throw new \LogicException('not used here');
    }

    public function ingestStoredObject(
        StoredObjectDescriptor $object,
        string $collectionKey,
        ?string $originalName = null,
        ?string $createdBy = null,
    ): MediaAssetReference {
        throw new \LogicException('not used here');
    }

    public function queueRegeneration(string $assetId, ?string $variantKey = null): void
    {
        throw new \LogicException('not used here');
    }
}
