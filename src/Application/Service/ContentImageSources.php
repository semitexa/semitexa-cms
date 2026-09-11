<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Media\Domain\Contract\MediaUrlGeneratorInterface;

/**
 * Which image addresses stored markup may keep.
 *
 * The sanitizer allowlists elements and attributes; it has no idea what a
 * legitimate `src` looks like HERE. This does, and it is deliberately a
 * separate object: the answer depends on the installation's storage and on the
 * tenant the request is acting as, neither of which is a fact about markup.
 *
 * Two shapes are ours, and nothing else is:
 *
 *   1. `/os/app/cms/media/<assetId>` — the identifier route. Everything the
 *      editor uploads is addressed this way and always will be: the route
 *      redirects to wherever the bytes currently are, so an article outlives
 *      a storage move. This is the shape to prefer, and the only one the
 *      editor can produce.
 *
 *   2. `<public prefix>/…` — an object the media package itself publishes for
 *      THIS tenant. Not the preferred shape, but a real one: a module whose
 *      content predates the CMS, or which renders pictures through
 *      `MediaUrlGenerator`, holds markup full of these. Before this existed
 *      they all failed the test, and opening such a record in the console and
 *      pressing save deleted every picture in it without a word.
 *
 * Query strings are tolerated rather than required to be absent, because we
 * put them there: `MediaUrlGenerator` appends `?v=<timestamp>` so a replaced
 * object is not served from a cache. Refusing a query would have refused our
 * own URLs.
 *
 * What is NOT ours stays not ours. A foreign host is a tracking pixel, a
 * request that leaves with the reader's address, or a picture that vanishes
 * when someone else's server does. The prefix test is anchored at the front
 * and the whole remainder is checked, because a plain `str_contains` would
 * take `https://evil.test/?u=https://cdn.ours/media/t/` and a bare prefix
 * would take `…/media/t/../../other-tenant/private.jpg`.
 */
#[AsService]
final class ContentImageSources
{
    /**
     * The identifier route, whole.
     *
     * Anchored at both ends on purpose — a prefix match would accept
     * `/os/app/cms/media/../../something`, and a suffix match would accept
     * `https://evil.test/os/app/cms/media/x`.
     */
    private const CMS_ROUTE = '#^/os/app/cms/media/[A-Za-z0-9._~-]+$#';

    /** What may follow the public prefix: storage path characters and nothing else. */
    private const OBJECT_PATH = '#^[A-Za-z0-9._~/-]+$#';

    /** What a query may carry. Ours is `v=<int>`; a hand-written `w=800` is not worth refusing. */
    private const QUERY = '#^[A-Za-z0-9._~%=&+,:/-]*$#';

    /**
     * Absent on an installation without the media package wired — a bare
     * `new` in a unit test, an install that never ingested anything. Then
     * only the identifier route is ours, which is the safe direction to fail.
     */
    #[InjectAsReadonly]
    protected MediaUrlGeneratorInterface $media;

    /**
     * @param string $src the value of a `src` attribute, entity-decoded
     */
    public function allows(string $src): bool
    {
        $src = trim($src);

        if ($src === '' || self::escapesItsPrefix($src)) {
            return false;
        }

        [$address, $query] = self::split($src);

        if (preg_match(self::QUERY, $query) !== 1) {
            return false;
        }

        if (preg_match(self::CMS_ROUTE, $address) === 1) {
            return true;
        }

        $prefix = $this->publicPrefix();

        if ($prefix === '' || !str_starts_with($address, $prefix)) {
            return false;
        }

        $rest = substr($address, strlen($prefix));

        return $rest !== '' && preg_match(self::OBJECT_PATH, $rest) === 1;
    }

    /**
     * Whether the address carries anything that could walk out of the prefix
     * it appears to be inside.
     *
     * Checked on the raw string and on one round of percent-decoding, because
     * a prefix test is a comparison of bytes and `%2e%2e%2f` is the same
     * traversal spelled so those bytes agree. A URL we generated never
     * contains any of this, so refusing all of it costs nothing real.
     */
    private static function escapesItsPrefix(string $src): bool
    {
        $lowered = strtolower($src);

        foreach ([$lowered, strtolower(rawurldecode($src))] as $candidate) {
            if (
                str_contains($candidate, '..')
                || str_contains($candidate, '\\')
                || str_contains($candidate, '%2e')
                || preg_match('/[\x00-\x20]/', $candidate) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Address and query, with any fragment discarded — a `#` in an image src
     * addresses nothing and must not be carried into a prefix comparison.
     *
     * @return array{string, string}
     */
    private static function split(string $src): array
    {
        $hash = strpos($src, '#');

        if ($hash !== false) {
            $src = substr($src, 0, $hash);
        }

        $mark = strpos($src, '?');

        return $mark === false
            ? [$src, '']
            : [substr($src, 0, $mark), substr($src, $mark + 1)];
    }

    /**
     * Read per call rather than memoized: the prefix carries the tenant, and a
     * service shared across coroutines that remembers one request's tenant
     * hands it to the next one.
     */
    private function publicPrefix(): string
    {
        return isset($this->media) ? $this->media->publicUrlPrefix() : '';
    }
}
