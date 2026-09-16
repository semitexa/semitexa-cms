<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

use Semitexa\Cms\Domain\Contract\ContentEditorInterface;

/**
 * One editable field of a record.
 *
 * The kind is what the editor needs to render something usable — a title is a
 * line, a body is a page of text — and nothing more.
 *
 * ## The committed set
 *
 * These six, and a module may rely on exactly this list. Each row says what
 * the console RENDERS and, more importantly, what the value looks like when it
 * arrives at {@see \Semitexa\Cms\Domain\Contract\ContentEditorInterface::save()},
 * because that is the half a module has to store:
 *
 * | kind    | the author sees      | `save()` receives                              |
 * |---------|----------------------|------------------------------------------------|
 * | `LINE`  | a one-line input     | the text, trimmed by the browser's own submit   |
 * | `TEXT`  | a plain textarea     | the text as typed, no markup rules applied      |
 * | `HTML`  | the rich editor      | markup ALREADY reduced to the CMS allowlist     |
 * | `IMAGE` | a picker + preview   | a media asset id, or `''` when cleared          |
 * | `DATE`  | a native date picker | `YYYY-MM-DD`, or `''` when cleared              |
 * | `BLOCKS`| a page of blocks     | ONE opaque string the CMS owns and the module    |
 * |         |                      | never parses — see the constant                 |
 *
 * Every one of them is a string, because {@see ContentEditorInterface::save()}
 * takes `array<string, string>` and every module already implements it. A
 * richer kind that needed a different value type could not ride that contract
 * without changing it for everyone — which is why the answer to "we need a
 * range" is two fields, not a new value shape (see {@see date()}).
 *
 * ## What is deliberately NOT here
 *
 * A relation, a list of OTHER RECORDS, anything whose value is not a string.
 * Those are a different contract, and adding one as a general-purpose escape
 * hatch — a JSON blob in a textarea an author types into — would make the
 * console a worse editor than the SQL it replaced.
 *
 * {@see self::BLOCKS} is not that escape hatch and is worth being precise
 * about, because this paragraph used to predict it would need a contract
 * migration and it did not. The author never sees the encoding; the CMS owns
 * it end to end; the module stores an opaque string exactly as it already
 * stores an asset id for an IMAGE. The prediction was right about the shape
 * and wrong about the price.
 *
 * An unknown kind is not an error: the editor falls back to a textarea, which
 * degrades to something an author can still use rather than to a blank space.
 */
final readonly class ContentField
{
    public const LINE = 'line';
    public const TEXT = 'text';
    public const HTML = 'html';

    /**
     * A page as an ordered list of blocks, carried as ONE string the CMS owns.
     *
     * The kind this docblock said would have to arrive one day, and the reason
     * it can ride the existing contract after all: the value is opaque to the
     * module, exactly as {@see self::IMAGE}'s asset id is. The module stores a
     * string it never parses; {@see \Semitexa\Cms\Application\Service\ContentBlockCodec}
     * owns what it means. So save(array<string, string>) is untouched and no
     * module that implements the editor contract changes.
     *
     * What this buys is the thing a rich-text field could not hold: LAYOUT. A
     * block carries where it sits and how large it is, as named choices the
     * skin renders — measured against the vendored editor, Trix 2.1.19 parses a
     * block attribute value and serialises it away, so an author who aligned a
     * paragraph lost it on the next save. Here no editor is ever handed the
     * layout.
     *
     * A value that is not in this format is not an error: it is a page written
     * before the format existed, and the codec reads it as one text block
     * holding exactly what was there.
     */
    public const BLOCKS = 'blocks';

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

    /**
     * A calendar day, carried as `YYYY-MM-DD` and nothing else.
     *
     * ISO on the wire, the author's own locale on screen: a native
     * `<input type="date">` formats the picker however the reader's system
     * formats dates and submits ISO regardless, so «locale-aware» costs no
     * parsing code and no library. Writing a display format into storage is
     * what makes a record unreadable from another locale later.
     *
     * A time of day is not part of this. A day is what a page's schedule is
     * about, and a kind that sometimes carries a time is two kinds wearing one
     * name — the editor would have to guess which, and so would every module
     * reading it back.
     *
     * An empty string means no date, and is what an author clearing one sends
     * back. Same rule as {@see IMAGE}: a module that skips empties makes the
     * date un-removable.
     */
    public const DATE = 'date';

    /** How a DATE value is spelled, in both directions. */
    public const DATE_FORMAT = 'Y-m-d';

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

    public static function blocks(string $name, string $label, string $value = '', bool $required = false, string $hint = ''): self
    {
        return new self($name, $label, $value, self::BLOCKS, $required, $hint);
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
     * A calendar day.
     *
     * **A range is two of these, not one field with two values.** An event with
     * an optional end is a required start plus a second, non-required date —
     * which the `$required` flag already expresses, and which keeps every value
     * a plain string the way {@see ContentEditorInterface::save()} needs. The
     * alternative, one field carrying `start..end`, would invent an encoding
     * the contract does not have and put both ends behind one control: exactly
     * the shape that let an edit write one date into both ends of a range and
     * go unnoticed.
     *
     * @param string $date `YYYY-MM-DD`, or '' for no date yet. A value that is
     *        not a calendar day is kept as-is rather than silently zeroed — the
     *        console reports it when the author next saves, which is where they
     *        can do something about it.
     */
    public static function date(string $name, string $label, string $date = '', bool $required = false, string $hint = ''): self
    {
        return new self($name, $label, trim($date), self::DATE, $required, $hint);
    }

    /** Whether a submitted value is a calendar day this field could hold. */
    public static function isCalendarDay(string $value): bool
    {
        if ($value === '') {
            return true; // no date; emptiness is handled by `required`, not here
        }

        $parsed = \DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);

        // A round trip, not just a successful parse: createFromFormat happily
        // reads '2026-02-31' and hands back March 3rd, so a date nobody could
        // have meant would otherwise be stored as a different one.
        return $parsed !== false && $parsed->format(self::DATE_FORMAT) === $value;
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
