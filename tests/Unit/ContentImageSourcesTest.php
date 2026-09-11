<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentImageSources;
use Semitexa\Media\Domain\Contract\MediaUrlGeneratorInterface;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * Which image addresses an article may keep.
 *
 * Written from the failure, not from the rule: a record whose pictures were
 * put there by something other than this editor — a module older than the CMS,
 * a page rendered through MediaUrlGenerator — opened in the console, and one
 * press of «Зберегти» emptied it. Every address the installation itself serves
 * has to survive that, and nothing else may.
 */
final class ContentImageSourcesTest extends TestCase
{
    use BuildsContainerManagedObjects;

    private const PREFIX = 'https://cdn.example.test/bucket/media/tenant-a/';

    private function sources(string $publicPrefix = self::PREFIX): ContentImageSources
    {
        return self::createWithDependencies(ContentImageSources::class, [
            'media' => new class ($publicPrefix) implements MediaUrlGeneratorInterface {
                public function __construct(private readonly string $prefix)
                {
                }

                public function url(string $assetId, ?string $variantKey = null): string
                {
                    return $this->prefix . $assetId;
                }

                public function publicUrlPrefix(): string
                {
                    return $this->prefix;
                }
            },
        ]);
    }

    /** The shape the editor produces, and the one an article should prefer. */
    #[Test]
    public function the_identifier_route_is_ours(): void
    {
        self::assertTrue($this->sources()->allows('/os/app/cms/media/01J8ABC-xyz'));
    }

    /**
     * Ours even where no media is published at all — the identifier route
     * redirects, so it is the one address that works on every installation.
     */
    #[Test]
    public function the_identifier_route_is_ours_without_published_storage(): void
    {
        self::assertTrue($this->sources('')->allows('/os/app/cms/media/01J8ABC-xyz'));
    }

    /**
     * The bug this exists for. An object the media package publishes for this
     * tenant is ours however it got into the markup.
     */
    #[Test]
    public function an_object_this_tenant_publishes_is_ours(): void
    {
        self::assertTrue($this->sources()->allows(self::PREFIX . 'content/asset-1/content.webp'));
    }

    /**
     * `MediaUrlGenerator` appends `?v=<timestamp>` so a replaced object is not
     * served from a cache. Refusing a query would refuse our own URLs.
     */
    #[Test]
    #[DataProvider('ourAddressesWithQueries')]
    public function a_query_string_does_not_make_an_address_foreign(string $src): void
    {
        self::assertTrue($this->sources()->allows($src));
    }

    /** @return iterable<string, array{string}> */
    public static function ourAddressesWithQueries(): iterable
    {
        yield 'version on a published object' => [self::PREFIX . 'content/a/content.webp?v=1757500000'];
        yield 'version on the identifier route' => ['/os/app/cms/media/abc?v=17'];
        yield 'a fragment addresses nothing' => ['/os/app/cms/media/abc#top'];
    }

    #[Test]
    #[DataProvider('foreignAddresses')]
    public function everything_else_is_refused(string $src): void
    {
        self::assertFalse($this->sources()->allows($src));
    }

    /** @return iterable<string, array{string}> */
    public static function foreignAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'a foreign host' => ['https://evil.test/pixel.png'];
        yield 'protocol relative' => ['//evil.test/p.png'];
        yield 'a data uri' => ['data:image/svg+xml;base64,PHN2Zz4='];
        yield 'our route as a suffix' => ['https://evil.test/os/app/cms/media/x'];
        yield 'our prefix as a query value' => ['https://evil.test/?u=' . self::PREFIX . 'a/b.webp'];
        yield 'traversal out of the route' => ['/os/app/cms/media/../../../etc/passwd'];
        yield 'traversal out of the prefix' => [self::PREFIX . '../tenant-b/private.jpg'];
        yield 'encoded traversal out of the prefix' => [self::PREFIX . '%2e%2e/tenant-b/private.jpg'];
        yield 'a backslash instead of a slash' => [self::PREFIX . '..\\tenant-b\\private.jpg'];
        yield 'the prefix and nothing under it' => [self::PREFIX];
        yield 'a longer tenant that merely starts the same' => [
            'https://cdn.example.test/bucket/media/tenant-ab/content/a/b.webp',
        ];
        yield 'credentials smuggled before the host' => ['https://cdn.example.test@evil.test/bucket/media/tenant-a/a.webp'];
        yield 'a newline inside the address' => ["/os/app/cms/media/ab\nc"];
    }

    /**
     * Another tenant's object is not ours even on the same storage — the
     * tenant is a path segment, which is the whole reason the answer is a
     * prefix rather than a host.
     */
    #[Test]
    public function another_tenants_object_on_the_same_storage_is_refused(): void
    {
        self::assertFalse(
            $this->sources()->allows('https://cdn.example.test/bucket/media/tenant-b/content/a/b.webp'),
        );
    }

    /**
     * Storage that publishes nothing admits no absolute address at all — which
     * is a fresh install with the local driver, so this is the default posture
     * rather than an edge case.
     */
    #[Test]
    public function unpublished_storage_admits_no_absolute_address(): void
    {
        self::assertFalse($this->sources('')->allows(self::PREFIX . 'content/a/b.webp'));
    }
}
