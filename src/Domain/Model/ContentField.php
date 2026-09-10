<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * One editable field of a record.
 *
 * The kind is what the editor needs to render something usable — a title is a
 * line, a body is a page of text — and nothing more. Anything richer (relations)
 * is a later kind, not a general-purpose escape hatch.
 */
final readonly class ContentField
{
    public const LINE = 'line';
    public const TEXT = 'text';
    public const HTML = 'html';

    /**
     * A single image, carried as a MEDIA ASSET ID and nothing else.
     *
     * Not a value object with a preview URL and dimensions, for two reasons.
     * {@see \Semitexa\Cms\Domain\Contract\ContentEditorInterface::save()}
     * hands a module `array<string, string>`, so a richer value could not ride
     * the contract without changing it for every module that already
     * implements it. And a URL stored beside the id would be a second copy of a
     * fact the media service owns — the console route exists precisely so that
     * moving the storage does not invalidate stored content, and a cached URL
     * would undo that.
     *
     * The URL is derived when something needs to render, by {@see previewUrl()}
     * here and by the media service on the public page. An empty value means no
     * image, and is what an author clearing one sends back.
     */
    public const IMAGE = 'image';

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

    /** @param string $assetId the media asset, or '' for no image yet */
    public static function image(string $name, string $label, string $assetId = '', bool $required = false, string $hint = ''): self
    {
        return new self($name, $label, trim($assetId), self::IMAGE, $required, $hint);
    }

    /**
     * Where the console reads this image from, or '' when there is none.
     *
     * One place, so the id-to-URL rule is not spelled out again in every view
     * that shows a picture. The route redirects to wherever the bytes actually
     * live, which is why nothing stores its answer.
     */
    public function previewUrl(): string
    {
        return $this->kind === self::IMAGE && $this->value !== ''
            ? '/os/app/cms/media/' . rawurlencode($this->value)
            : '';
    }
}
