<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Contract;

use Semitexa\Cms\Domain\Model\ContentDraft;

/**
 * How a module lets its records be edited from the console.
 *
 * The CMS knows nothing about pages, events or exhibitions. It knows that a
 * place on the map names an editor, that an editor can produce fields for a
 * record and take them back — and that the write goes through the module's own
 * repository, never into the graph. The graph is a map; the table is the site.
 */
interface ContentEditorInterface
{
    /** Matches the `editor` a Place declares, e.g. 'regmus:page'. */
    public function editorId(): string;

    /** The record's current state, or null when the ref names nothing. */
    public function load(string $ref): ?ContentDraft;

    /**
     * Apply submitted values to the record.
     *
     * @param array<string, string> $values Keyed by field name; fields the editor
     *        did not declare are the caller's noise and must be ignored.
     * @throws \InvalidArgumentException when the values cannot make a valid record
     */
    public function save(string $ref, array $values): void;
}
