<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

use Semitexa\Weave\Domain\Enum\NodeKind;

/**
 * One place, and whether the console can open it.
 *
 * A record rather than an array shape: the checker, the console output, the
 * JSON envelope and the snapshot all read the same fields, and a hand-written
 * `array{}` that drifts hides the branch nobody took.
 */
final readonly class PlaceCheck
{
    public function __construct(
        public string $ref,
        public string $title,
        public ?string $parentRef,
        public NodeKind $kind,
        public int $order,
        /** The editor or source the place names, for a message that says which. */
        public ?string $opens,
        public PlaceVerdict $verdict,
    ) {}

    public static function reachable(Place $place): self
    {
        return self::of($place, PlaceVerdict::Reachable);
    }

    public static function of(Place $place, PlaceVerdict $verdict): self
    {
        return new self(
            ref: $place->ref,
            title: $place->title,
            parentRef: $place->parentRef,
            kind: $place->kind,
            order: $place->order,
            opens: $place->isCollection() ? $place->source : $place->editor,
            verdict: $verdict,
        );
    }

    /** @return array{ref: string, title: string, parent: string|null, kind: string, order: int, opens: string|null, verdict: string} */
    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'title' => $this->title,
            'parent' => $this->parentRef,
            'kind' => $this->kind->value,
            'order' => $this->order,
            'opens' => $this->opens,
            'verdict' => $this->verdict->value,
        ];
    }

    /** One line a person can read without decoding anything. */
    public function explain(): string
    {
        return match ($this->verdict) {
            PlaceVerdict::Reachable => sprintf('%s — opens', $this->title),
            PlaceVerdict::EditorMissing => sprintf(
                '%s (%s) names the editor "%s" and no module registers it',
                $this->title,
                $this->ref,
                (string) $this->opens,
            ),
            PlaceVerdict::RecordMissing => sprintf(
                '%s (%s) is on the map and its record is gone — the console offers it and cannot open it',
                $this->title,
                $this->ref,
            ),
            PlaceVerdict::SourceMissing => sprintf(
                '%s (%s) lists "%s" and nothing provides those rows: it opens an empty grid',
                $this->title,
                $this->ref,
                (string) $this->opens,
            ),
            PlaceVerdict::OrphanParent => sprintf(
                '%s (%s) hangs from "%s", which is not on the map — nothing links to it',
                $this->title,
                $this->ref,
                (string) $this->parentRef,
            ),
            PlaceVerdict::DuplicateRef => sprintf(
                '%s claims the ref "%s", which another place already claims',
                $this->title,
                $this->ref,
            ),
        };
    }
}
