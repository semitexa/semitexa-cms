<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

use Semitexa\Weave\Domain\Enum\NodeKind;

/**
 * One place on a site's map.
 *
 * A place is not a record. The museum has 41 rows of type `page`, and about
 * nine of them are places someone navigates to; the rest are the contents of an
 * exhibition, which belong in a list. So the map is authored — a module says
 * which of its records are places — rather than derived by dumping a table into
 * the graph and hoping the shape means something.
 *
 * Two sorts, and the difference is the whole navigation model:
 *
 *  - {@see page()} — its own address, its own content. Opening it opens an
 *    editor.
 *  - {@see collection()} — a stack of same-shaped records. Opening it opens a
 *    grid, and the editor is reached from a row.
 */
final readonly class Place
{
    /**
     * @param array<string, mixed> $properties
     */
    private function __construct(
        public NodeKind $kind,
        public string $ref,
        public string $title,
        public ?string $parentRef = null,
        public ?string $editor = null,
        public ?string $source = null,
        public array $properties = [],
        public int $order = 0,
    ) {
    }

    /**
     * @param string      $ref    Stable identity of the underlying record, e.g. 'regmus:page:7'.
     * @param string|null $editor What opens it, e.g. 'regmus:page' — resolved by the console.
     * @param array<string, mixed> $properties
     */
    public static function page(
        string $ref,
        string $title,
        ?string $parentRef = null,
        ?string $editor = null,
        array $properties = [],
        int $order = 0,
    ): self {
        return new self(NodeKind::Page, self::requireRef($ref), $title, $parentRef, $editor, null, $properties, $order);
    }

    /**
     * @param string $source What lists the rows, e.g. 'regmus:events'. A collection
     *                       without one is a dead end: it would open an empty grid.
     * @param array<string, mixed> $properties
     */
    public static function collection(
        string $ref,
        string $title,
        string $source,
        ?string $parentRef = null,
        array $properties = [],
        int $order = 0,
    ): self {
        $source = trim($source);
        if ($source === '') {
            throw new \InvalidArgumentException("Collection '{$ref}' needs a source; without one it opens an empty grid.");
        }

        return new self(NodeKind::Collection, self::requireRef($ref), $title, $parentRef, null, $source, $properties, $order);
    }

    /** The site itself — the root every other place hangs from. */
    public static function site(string $ref, string $title, array $properties = []): self
    {
        return new self(NodeKind::Site, self::requireRef($ref), $title, null, null, null, $properties, 0);
    }

    public function isCollection(): bool
    {
        return $this->kind === NodeKind::Collection;
    }

    /**
     * What the graph stores alongside the node. `opens` is the question every
     * view asks first, so it is answered explicitly rather than inferred from
     * which of editor/source happens to be set.
     *
     * @return array<string, mixed>
     */
    public function nodeProperties(): array
    {
        return array_merge($this->properties, array_filter([
            'origin' => 'site',
            'opens' => $this->isCollection() ? 'grid' : 'editor',
            'editor' => $this->editor,
            'source' => $this->source,
            'order' => $this->order,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private static function requireRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            throw new \InvalidArgumentException('A place needs a ref: it is what makes it the same place after a rename.');
        }

        return $ref;
    }
}
