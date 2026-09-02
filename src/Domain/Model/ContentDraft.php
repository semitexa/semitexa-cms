<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * What the editor shows for one record: its title, its fields, and where it can
 * be seen live.
 *
 * The public URL is not decoration. Someone editing the contacts page is
 * changing something a visitor will read, and the shortest way to be sure it
 * came out right is to look at it.
 */
final readonly class ContentDraft
{
    /**
     * @param list<ContentField> $fields
     */
    public function __construct(
        public string $ref,
        public string $title,
        public array $fields,
        public ?string $publicUrl = null,
    ) {
    }
}
