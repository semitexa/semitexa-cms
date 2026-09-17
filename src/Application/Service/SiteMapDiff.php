<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\MapChangeKind;
use Semitexa\Cms\Domain\Model\MapChangeRecord;

/**
 * What changed between a map taken earlier and the map as it is now.
 *
 * This is the reconciliation a move owes the person whose site it is. A move
 * reports success and a count; a count cannot tell forty-one rows from the nine
 * of them that are places, which is how a museum's whole structure branch left
 * without anything saying so.
 *
 * Pure over two arrays of place rows, so the interesting question — does it
 * NAME the thing that disappeared — is answered in a unit test rather than by
 * doing a migration and looking.
 */
final class SiteMapDiff
{
    /**
     * @param array<string, mixed> $before snapshot payload: siteRef => {title, places[]}
     * @param array<string, mixed> $after  the same shape, taken now
     *
     * @return array<string, list<MapChangeRecord>> siteRef => changes
     */
    public function between(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $siteRef => $site) {
            $siteRef = (string) $siteRef;
            // BOTH sides guarded. A snapshot is a file somebody can edit, and
            // the reader before this only checks that it parses as JSON — a
            // site that came back as a string was then indexed with ['places']
            // and PHP 8.4 raised a TypeError before any report was written.
            $previous = $before[$siteRef] ?? [];

            $changes[$siteRef] = $this->forSite(
                $this->byRef((array) ((is_array($previous) ? $previous : [])['places'] ?? [])),
                $this->byRef((array) ((is_array($site) ? $site : [])['places'] ?? [])),
            );
        }

        // A site present in the snapshot and absent now is the loudest finding
        // there is: not a page missing, a whole map.
        foreach (array_keys($before) as $siteRef) {
            $siteRef = (string) $siteRef;
            if (isset($after[$siteRef])) {
                continue;
            }

            $changes[$siteRef][] = new MapChangeRecord(
                MapChangeKind::SiteGone,
                $siteRef,
                sprintf('the whole map for "%s" is gone', $siteRef),
            );
        }

        return $changes;
    }

    /**
     * @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $after
     * @return list<MapChangeRecord>
     */
    private function forSite(array $before, array $after): array
    {
        $changes = [];

        foreach ($before as $ref => $place) {
            if (isset($after[$ref])) {
                continue;
            }

            $changes[] = new MapChangeRecord(
                MapChangeKind::Gone,
                $ref,
                sprintf('gone: %s (%s)', $this->title($place, $ref), $ref),
            );
        }

        foreach ($after as $ref => $place) {
            if (!isset($before[$ref])) {
                $changes[] = new MapChangeRecord(
                    MapChangeKind::New,
                    $ref,
                    sprintf('new: %s (%s)', $this->title($place, $ref), $ref),
                );
                continue;
            }

            $was = $before[$ref];

            if (($was['title'] ?? null) !== ($place['title'] ?? null)) {
                $changes[] = new MapChangeRecord(
                    MapChangeKind::Renamed,
                    $ref,
                    sprintf('renamed: %s → %s (%s)', $this->title($was, $ref), $this->title($place, $ref), $ref),
                );
            }

            if (($was['parent'] ?? null) !== ($place['parent'] ?? null)) {
                $changes[] = new MapChangeRecord(
                    MapChangeKind::Moved,
                    $ref,
                    sprintf(
                        'moved: %s now hangs from %s (was %s)',
                        $this->title($place, $ref),
                        (string) ($place['parent'] ?? '—'),
                        (string) ($was['parent'] ?? '—'),
                    ),
                );
            }

            if (($was['opens'] ?? null) !== ($place['opens'] ?? null)) {
                $changes[] = new MapChangeRecord(
                    MapChangeKind::SourceChanged,
                    $ref,
                    sprintf(
                        'now opens something else: %s — %s (was %s)',
                        $this->title($place, $ref),
                        self::describe($place['opens'] ?? null),
                        self::describe($was['opens'] ?? null),
                    ),
                );
            }

            if (($was['verdict'] ?? null) !== ($place['verdict'] ?? null)) {
                $changes[] = new MapChangeRecord(
                    MapChangeKind::VerdictChanged,
                    $ref,
                    sprintf(
                        '%s: %s → %s',
                        $this->title($place, $ref),
                        (string) ($was['verdict'] ?? '?'),
                        (string) ($place['verdict'] ?? '?'),
                    ),
                );
            }
        }

        return $changes;
    }

    /** What a place opens — an editor id or a collection source — or a dash. */
    private static function describe(mixed $opens): string
    {
        return is_string($opens) && $opens !== '' ? $opens : '—';
    }

    /**
     * @param array<mixed> $places
     * @return array<string, array<string, mixed>>
     */
    private function byRef(array $places): array
    {
        $out = [];
        foreach ($places as $place) {
            if (is_array($place) && is_string($place['ref'] ?? null)) {
                $out[$place['ref']] = $place;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $place */
    private function title(array $place, string $fallback): string
    {
        $title = $place['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : $fallback;
    }
}
