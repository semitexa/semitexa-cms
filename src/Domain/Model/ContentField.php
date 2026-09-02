<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * One editable field of a record.
 *
 * The kind is what the editor needs to render something usable — a title is a
 * line, a body is a page of text — and nothing more. Anything richer (media
 * pickers, relations) is a later kind, not a general-purpose escape hatch.
 */
final readonly class ContentField
{
    public const LINE = 'line';
    public const TEXT = 'text';
    public const HTML = 'html';

    public function __construct(
        public string $name,
        public string $label,
        public string $value = '',
        public string $kind = self::LINE,
        public bool $required = false,
        public string $hint = '',
    ) {
    }

    public static function line(string $name, string $label, string $value = '', bool $required = false, string $hint = ''): self
    {
        return new self($name, $label, $value, self::LINE, $required, $hint);
    }

    public static function text(string $name, string $label, string $value = '', bool $required = false, string $hint = ''): self
    {
        return new self($name, $label, $value, self::TEXT, $required, $hint);
    }

    public static function html(string $name, string $label, string $value = '', bool $required = false, string $hint = ''): self
    {
        return new self($name, $label, $value, self::HTML, $required, $hint);
    }
}
