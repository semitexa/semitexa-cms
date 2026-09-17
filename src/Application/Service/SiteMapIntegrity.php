<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\Place;
use Semitexa\Cms\Domain\Model\PlaceCheck;
use Semitexa\Cms\Domain\Model\PlaceVerdict;
use Semitexa\Weave\Domain\Enum\NodeKind;

/**
 * Asks, of every place on a site's map, whether the console can open it.
 *
 * WHY THIS EXISTS, in the words of the person it failed: a whole branch of a
 * museum's map — its structure, with every page under "About the museum" — was
 * simply not there after a move, and a page called "Video" would not open. He
 * found both. The move had reported success, and success meant a row count:
 * {@see Place} says in its own docblock that the site has 41 page rows and
 * about nine of them are places, so a count could never have told the
 * difference.
 *
 * The check is deliberately about what the MAP knows. A place names an editor
 * or a source; both resolve through {@see ContentSurfaceRegistry}, and an
 * editor answers `load($ref)` with null when the ref names nothing. That is the
 * console's own question, asked before an author asks it by clicking.
 *
 * NOT what it checks: whether the public page answers at its public URL. A
 * Place carries no address — `opens: editor` and `opens: grid` are console
 * verbs — so a public 404 is a different question owned by the site's routing,
 * and answering it here would mean inventing an address the map never had.
 *
 * Pure with respect to storage: it takes places and a resolver, so a test can
 * hand it a map without a database and the same code runs in the command.
 */
final class SiteMapIntegrity
{
    /**
     * @param iterable<Place> $places
     * @param callable(string): bool $editorExists    does a module register this editor id
     * @param callable(string, string): bool $recordExists  does that editor load this ref
     * @param callable(string): bool $sourceExists    does anything list this collection source
     *
     * @return list<PlaceCheck>
     */
    public function check(
        iterable $places,
        callable $editorExists,
        callable $recordExists,
        callable $sourceExists,
    ): array {
        /** @var list<Place> $all */
        $all = is_array($places) ? array_values($places) : iterator_to_array($places, false);

        $seen = [];
        $known = [];
        foreach ($all as $place) {
            $known[$place->ref] = true;
        }

        $reachable = self::reachableFromRoot($all);

        $checks = [];

        foreach ($all as $place) {
            $verdict = $this->verdictFor($place, $known, $seen, $editorExists, $recordExists, $sourceExists);

            // A place whose content resolves is still not a place anyone can
            // GET TO. Checked after the content verdict so a broken editor is
            // still named as a broken editor — being unreachable as well does
            // not make that the more useful thing to say.
            if ($verdict === PlaceVerdict::Reachable && !isset($reachable[$place->ref])) {
                $verdict = PlaceVerdict::Unreachable;
            }

            $seen[$place->ref] = true;
            $checks[] = PlaceCheck::of($place, $verdict);
        }

        return $checks;
    }

    /**
     * Every ref the site root can actually be walked to.
     *
     * A BREADTH-FIRST walk down from the sites, not a look at one edge. The
     * check before this asked only whether a parent record exists, which a
     * place hanging from nothing satisfies trivially, and so does a pair of
     * places naming each other — both were reported Reachable while no visitor
     * could ever arrive.
     *
     * @param list<Place> $all
     * @return array<string, true>
     */
    private static function reachableFromRoot(array $all): array
    {
        $children = [];
        $roots = [];

        foreach ($all as $place) {
            if ($place->kind === NodeKind::Site) {
                $roots[] = $place->ref;
                continue;
            }

            $children[(string) $place->parentRef][] = $place->ref;
        }

        $reachable = [];
        $queue = $roots;

        while ($queue !== []) {
            $ref = array_shift($queue);
            if (isset($reachable[$ref])) {
                // A cycle, or two parents naming the same child. Either way
                // this ref is already accounted for and re-walking it would
                // not terminate.
                continue;
            }

            $reachable[$ref] = true;

            foreach ($children[$ref] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $reachable;
    }

    /**
     * @param array<string, true> $known every ref on the map
     * @param array<string, true> $seen  refs already walked, for the duplicate check
     * @param callable(string): bool $editorExists
     * @param callable(string, string): bool $recordExists
     * @param callable(string): bool $sourceExists
     */
    private function verdictFor(
        Place $place,
        array $known,
        array $seen,
        callable $editorExists,
        callable $recordExists,
        callable $sourceExists,
    ): PlaceVerdict {
        // First, because a duplicate ref makes every later answer about this
        // place ambiguous — including which record it would have loaded.
        if (isset($seen[$place->ref])) {
            return PlaceVerdict::DuplicateRef;
        }

        // The site is the root: it hangs from nothing and opens nothing.
        if ($place->kind === NodeKind::Site) {
            return PlaceVerdict::Reachable;
        }

        if ($place->parentRef !== null && !isset($known[$place->parentRef])) {
            return PlaceVerdict::OrphanParent;
        }

        if ($place->isCollection()) {
            return $sourceExists((string) $place->source)
                ? PlaceVerdict::Reachable
                : PlaceVerdict::SourceMissing;
        }

        $editor = (string) $place->editor;
        if ($editor === '' || !$editorExists($editor)) {
            return PlaceVerdict::EditorMissing;
        }

        // The museum's symptom, and the one a row count cannot see.
        return $recordExists($editor, $place->ref)
            ? PlaceVerdict::Reachable
            : PlaceVerdict::RecordMissing;
    }

    /**
     * @param list<PlaceCheck> $checks
     * @return list<PlaceCheck>
     */
    public function problems(array $checks): array
    {
        return array_values(array_filter($checks, static fn (PlaceCheck $c): bool => $c->verdict->isProblem()));
    }
}
