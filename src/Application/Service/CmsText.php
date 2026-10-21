<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Os\Application\Service\OsPreferences;
use Semitexa\Os\Application\Service\OsReplies;
use Semitexa\Ssr\Application\Service\I18n\Translator;

/**
 * What the content console says, in the language the operator chose.
 *
 * Every sentence the console shows a person goes through here with its English
 * written beside the key. The catalogs (`src/Application/View/locales/*.json`,
 * keys stored WITHOUT the `cms.` prefix — the catalog adds the module name)
 * improve on that English; they never replace it as the last resort. The
 * resolution order is {@see OsReplies::resolve()}'s and deliberately not a copy
 * of it: catalog(locale), then catalog(en), then the caller's English, and
 * never the raw key — the console renders in CLI ticks and tests too, where no
 * catalog was ever loaded.
 */
final class CmsText
{
    private const PREFIX = 'cms.';

    /** The id of the JSON block that carries {@see self::scriptStrings()} to the page's scripts. */
    public const SCRIPT_ID = 'cms-strings';

    /**
     * Plain text: for a message handed on as a string (a validation error, a
     * notice) that whoever renders it escapes.
     *
     * @param array<string, string|int|float> $params placeholders by bare name,
     *        written as `{{name}}` in both the catalog and the fallback
     */
    public static function t(string $key, array $params, string $fallback): string
    {
        return OsReplies::resolve(self::PREFIX . $key, $params, $fallback);
    }

    /**
     * The same sentence as HTML, safe to drop into markup.
     *
     * The SENTENCE is escaped as well as the values: a catalog entry is text,
     * and a per-tenant override is text somebody typed. Each `{{name}}` is then
     * filled with its escaped value, or — for the few placeholders that ARE
     * markup, like a `<code>` command — with the `$markup` value verbatim.
     *
     * @param array<string, string|int|float> $params escaped on the way in
     * @param array<string, string>           $markup inserted as given
     */
    public static function html(string $key, array $params, string $fallback, array $markup = []): string
    {
        $placeholders = [];
        foreach (array_keys($params + $markup) as $name) {
            $placeholders[$name] = '{{' . $name . '}}';
        }

        $out = self::escape(self::t($key, $placeholders, $fallback));
        foreach ($params as $name => $value) {
            $out = str_replace('{{' . $name . '}}', self::escape((string) $value), $out);
        }
        foreach ($markup as $name => $value) {
            $out = str_replace('{{' . $name . '}}', $value, $out);
        }

        return $out;
    }

    /**
     * The value for `<html lang>`: the language the console speaks, 'en' when
     * nothing can say.
     */
    public static function language(): string
    {
        try {
            $language = new OsPreferences()->language();
        } catch (\Throwable) {
            return 'en';
        }

        if (preg_match('/^[a-z]{2,3}$/', $language) !== 1) {
            return 'en';
        }
        // The page is in the operator's language only when the catalog has it:
        // `lang="de"` over English text makes a screen reader read English
        // with a German voice.
        try {
            return $language === 'en' || Translator::getCatalog()->has('cms.editor.save', $language) ? $language : 'en';
        } catch (\Throwable) {
            return 'en';
        }
    }

    /**
     * The strings the console's scripts show, resolved here so the browser
     * never needs a request of its own for them.
     *
     * Emitted as an inert JSON block (`type="application/json"` is not run, so
     * it needs no CSP nonce). Each script reads it with a `t(key, fallback)`
     * that keeps its own English: a page rendered without this block, or a key
     * added to a script before the catalog, still reads as a sentence.
     */
    public static function scriptStrings(): string
    {
        $strings = [
            'state.dirty' => self::t('js.state.dirty', [], 'Unsaved changes'),
            'state.saving' => self::t('js.state.saving', [], 'Saving…'),
            'state.saved' => self::t('editor.state.saved', [], 'Saved'),
            'image.replace' => self::t('image.replace', [], 'Replace'),
            'image.choose' => self::t('image.choose', [], 'Choose image'),
            'image.only_images' => self::t('js.image.only_images', [], 'Only images can be added: JPEG, PNG or WebP.'),
            'image.save_failed' => self::t('image.save_failed', [], 'Could not save the image.'),
            'upload.failed' => self::t('js.upload.failed', [], 'Could not upload the image.'),
            'upload.offline' => self::t('js.upload.offline', [], 'Could not upload the image — check your connection.'),
            'upload.too_slow' => self::t('js.upload.too_slow', [], 'The upload is taking too long. Try again.'),
        ];

        $json = json_encode(
            $strings,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
        );

        return '<script type="application/json" id="' . self::SCRIPT_ID . '">'
            . ($json === false ? '{}' : $json)
            . '</script>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
