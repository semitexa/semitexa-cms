<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use Semitexa\Os\Application\Service\OsPreferences;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\CmsText;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\I18n\Translator;
use Semitexa\Ssr\Application\Service\Server\Lifecycle\WireTranslationOverridesListener;

/**
 * The content console speaks the operator's language, not a hard-coded one.
 *
 * Every label, notice and validation message used to be a Ukrainian literal,
 * so an English-speaking operator got a Ukrainian editor whatever they chose.
 * Each one now goes through {@see CmsText} with its English beside the key, and
 * the catalogs in src/Application/View/locales improve on that English.
 *
 * Four things keep it that way: the two catalogs agree on keys and
 * placeholders; no Cyrillic creeps back into the sources outside an explicit
 * allow-list; the English at each call site matches the English catalog; and
 * the catalog is actually REACHABLE — registered for a package that ships no
 * Twig templates, which is the case that used to leave it unloaded.
 */
final class CmsLocaleCatalogTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';
    private const LOCALES = self::SRC . '/Application/View/locales';
    private const JS = self::SRC . '/Application/Static/js';

    /**
     * Files that may keep Cyrillic, each with the reason. Prompt text is model
     * input rather than UI and is allowed wholesale by directory below.
     */
    private const CYRILLIC_ALLOWED = [
        // Routing hints for the model: examples of how a Ukrainian operator
        // phrases the request. Model input, not something a person reads.
        'Application/Service/ContentEditorSkill.php',
        'Application/Service/ContentListSkill.php',
        // Comments whose subject IS Ukrainian inflection — the stemming they
        // explain cannot be illustrated in English.
        'Application/Service/SiteMapProjector.php',
    ];

    private const CYRILLIC_ALLOWED_DIRS = [
        'Application/View/locales/',
        'Application/Prompt/',
        'Application/Static/vendor/',
    ];

    private string|false $osLocale = false;

    protected function setUp(): void
    {
        Translator::reset();
        $this->osLocale = getenv('SEMITEXA_OS_LOCALE');
    }

    protected function tearDown(): void
    {
        Translator::setPackageLocaleDirs([]);
        Translator::reset();
        putenv($this->osLocale === false ? 'SEMITEXA_OS_LOCALE' : 'SEMITEXA_OS_LOCALE=' . $this->osLocale);
    }

    #[Test]
    public function both_catalogs_carry_the_same_keys_and_the_same_placeholders(): void
    {
        $en = $this->catalog('en');
        $uk = $this->catalog('uk');

        self::assertNotSame([], $en);
        self::assertSame(array_keys($en), array_keys($uk), 'en.json and uk.json disagree about which strings exist');

        foreach ($en as $key => $english) {
            self::assertNotSame('', trim($english), "{$key} is empty in en.json");
            self::assertNotSame('', trim($uk[$key]), "{$key} is empty in uk.json");
            self::assertSame(
                self::placeholders($english),
                self::placeholders($uk[$key]),
                "{$key}: the two languages fill different placeholders",
            );
            self::assertStringStartsNotWith('cms.', $key, 'keys are stored without the module prefix; the catalog adds it');
        }
    }

    #[Test]
    public function no_cyrillic_is_left_in_the_sources_outside_the_allow_list(): void
    {
        $offenders = [];
        $allowedSeen = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace(self::SRC . '/', '', $file->getPathname());

            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/[\x{0400}-\x{04FF}]/u', $source) !== 1) {
                continue;
            }

            foreach (self::CYRILLIC_ALLOWED_DIRS as $dir) {
                if (str_starts_with($relative, $dir)) {
                    continue 2;
                }
            }
            if (in_array($relative, self::CYRILLIC_ALLOWED, true)) {
                $allowedSeen[] = $relative;
                continue;
            }

            $offenders[] = $relative;
        }

        self::assertSame([], $offenders, 'user-facing text belongs in the locale catalogs, read through CmsText');

        // A stale entry is an exemption nobody needs any more, waiting for
        // someone to put a hard-coded string back under it.
        sort($allowedSeen);
        $expected = self::CYRILLIC_ALLOWED;
        sort($expected);
        self::assertSame($expected, $allowedSeen, 'an allow-listed file no longer has Cyrillic: drop it from the list');
    }

    /**
     * Each call site carries an English sentence AND the catalog carries one.
     * Nothing but this stops the two from drifting, and a drifted pair is
     * invisible: both render fine, in different words, depending on whether
     * the catalog happened to load.
     */
    #[Test]
    public function the_english_at_every_call_site_matches_the_english_catalog(): void
    {
        $en = $this->catalog('en');
        $calls = $this->callSites();

        self::assertGreaterThanOrEqual(60, count($calls), 'the call-site scan found almost nothing — it is no longer reading the sources');

        foreach ($calls as $key => $fallback) {
            self::assertArrayHasKey($key, $en, "CmsText key '{$key}' has no entry in en.json");
            self::assertSame($en[$key], $fallback, "the English written at the call site for '{$key}' has drifted from en.json");
        }

        self::assertSame([], array_values(array_diff(array_keys($en), array_keys($calls))), 'en.json holds keys no code reads');
    }

    /** The scripts' own English must be the English the server sends them. */
    #[Test]
    public function the_scripts_fall_back_to_the_same_english_the_server_sends(): void
    {
        $sent = $this->scriptStrings();
        $checked = 0;

        foreach (glob(self::JS . '/*.js') ?: [] as $file) {
            preg_match_all("/\\bt\\('([^']+)',\\s*'((?:[^'\\\\]|\\\\.)*)'\\)/u", (string) file_get_contents($file), $m, PREG_SET_ORDER);
            foreach ($m as [, $key, $fallback]) {
                self::assertArrayHasKey($key, $sent, basename($file) . " asks for '{$key}', which the page never sends");
                self::assertSame($sent[$key], stripslashes($fallback), basename($file) . ": '{$key}' falls back to different English");
                $checked++;
            }
        }

        self::assertGreaterThanOrEqual(10, $checked, 'the script scan found almost nothing');
    }

    /**
     * With no catalog loaded — a CLI tick, a test, an install whose translator
     * never booted — the console reads in English, never as raw keys.
     */
    #[Test]
    public function with_no_catalog_a_string_resolves_to_its_english(): void
    {
        putenv('SEMITEXA_OS_LOCALE=uk');

        self::assertSame('Fill in the field “Title”.', CmsText::t('save.required', ['field' => 'Title'], 'Fill in the field “{{field}}”.'));
        self::assertSame(
            'Remove “&lt;b&gt;”?',
            CmsText::html('remove.confirm.title', ['title' => '<b>'], 'Remove “{{title}}”?'),
        );
    }

    /**
     * And with the catalog registered the way a worker registers it — through
     * the module registry, for a package that has no templates directory — a
     * Ukrainian operator reads Ukrainian, and an English one English.
     */
    #[Test]
    public function the_catalog_is_discovered_for_the_package_and_answers_in_the_operators_language(): void
    {
        $dirs = WireTranslationOverridesListener::packageLocaleDirs(new ModuleRegistry()->getModules());

        self::assertArrayHasKey('cms', $dirs, 'the cms catalog is not registered — every string would stay English');
        self::assertSame(realpath(self::LOCALES), realpath($dirs['cms']));

        Translator::setPackageLocaleDirs($dirs);
        self::assertSame('Заповніть поле «{{field}}».', Translator::getCatalog()->get('cms.save.required', 'uk'), 'the Ukrainian catalog is loaded');

        putenv('SEMITEXA_OS_LOCALE=uk');
        // A stored OS language outranks the env: follow whatever the console is set to.
        if ((new OsPreferences())->language() !== 'uk') {
            self::assertSame('Fill in the field “Заголовок”.', CmsText::t('save.required', ['field' => 'Заголовок'], 'Fill in the field “{{field}}”.'));

            return;
        }
        self::assertSame('uk', CmsText::language());
        self::assertSame(
            'Заповніть поле «Заголовок».',
            CmsText::t('save.required', ['field' => 'Заголовок'], 'Fill in the field “{{field}}”.'),
        );
        self::assertSame(
            'Ця консоль показує сторінки і списки з карти сайту. Її ще не побудовано — <code>cms:map:build</code>.',
            CmsText::html(
                'answer.nothing_chosen.hint',
                [],
                'This console shows the pages and lists of the site map. The map has not been built yet — {{command}}.',
                ['command' => '<code>cms:map:build</code>'],
            ),
        );
        self::assertSame('Незбережені зміни', $this->scriptStrings()['state.dirty']);

        putenv('SEMITEXA_OS_LOCALE=en');
        self::assertSame('Saved.', CmsText::t('save.saved', [], 'Saved.'));
    }

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        $decoded = json_decode((string) file_get_contents(self::LOCALES . '/' . $locale . '.json'), true);
        self::assertIsArray($decoded, "{$locale}.json is not a JSON object");

        /** @var array<string, string> $decoded */
        return $decoded;
    }

    /** @return array<string, string> */
    private function scriptStrings(): array
    {
        $html = CmsText::scriptStrings();
        self::assertSame(1, preg_match('#<script type="application/json" id="cms-strings">(.*)</script>#s', $html, $m));
        $decoded = json_decode($m[1], true);
        self::assertIsArray($decoded);

        /** @var array<string, string> $decoded */
        return $decoded;
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{\{(\w+)\}\}/', $text, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    /**
     * key => the English written beside it, for every CmsText::t(),
     * CmsText::html() (and self:: inside CmsText) and `$say(` call in src.
     *
     * Read with PHP's tokenizer rather than a pattern: a regular expression
     * that parses PHP is one that quietly stops matching, and then reports
     * success on what it still finds. A fallback split over several
     * concatenated literals is joined; one built any other way is skipped.
     *
     * @return array<string, string>
     */
    private function callSites(): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            foreach ($this->callsIn((string) file_get_contents($file->getPathname())) as $key => $fallback) {
                self::assertTrue(
                    !isset($found[$key]) || $found[$key] === $fallback,
                    "'{$key}' is written with two different English sentences",
                );
                $found[$key] = $fallback;
            }
        }

        return $found;
    }

    /** @return array<string, string> */
    private function callsIn(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $t): bool => !is_array($t)
                || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $out = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (!$this->isTextCall($tokens, $i) || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $depth = 0;
            $arg = 0;
            $key = null;
            $fallback = [];
            $plain = true;

            for ($j = $i + 1; $j < $n; $j++) {
                $t = $tokens[$j];
                if ($t === '(' || $t === '[') {
                    $depth++;
                    continue;
                }
                if ($t === ')' || $t === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                    continue;
                }
                if ($t === ',' && $depth === 1) {
                    $arg++;
                    continue;
                }
                if ($depth !== 1) {
                    continue;
                }
                if ($arg === 0 && $key === null && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $key = self::literal($t[1]);
                } elseif ($arg === 2) {
                    if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $fallback[] = self::literal($t[1]);
                    } elseif ($t !== '.') {
                        $plain = false;
                    }
                }
            }

            if ($key !== null && $fallback !== [] && $plain) {
                $out[$key] = implode('', $fallback);
            }
        }

        return $out;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private function isTextCall(array $tokens, int $i): bool
    {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === '$say') {
            return true;
        }

        return is_array($t) && $t[0] === T_STRING && in_array($t[1], ['t', 'html'], true)
            && is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_DOUBLE_COLON
            && is_array($tokens[$i - 2] ?? null) && in_array($tokens[$i - 2][1], ['CmsText', 'self'], true);
    }

    private static function literal(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body)
            : stripcslashes($body);
    }
}
