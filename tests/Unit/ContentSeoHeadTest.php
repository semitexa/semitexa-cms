<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentSeoHead;
use Semitexa\Cms\Application\Service\SeoStore;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Ssr\Application\Service\Seo\SeoMeta;

/**
 * The last hop: stored metadata becoming metadata a reader's browser sees.
 *
 * Everything upstream of this produced a row in a table. Until the seam existed
 * none of it reached a `<head>`, which made the meta layer, the writer and the
 * debounce an elaborate way of describing pages to nobody.
 *
 * Against a real store rather than a double, because the question is whether
 * what the CMS actually persists comes back out in the right channel — a stub
 * would only prove that this class can read whatever it is handed.
 */
final class ContentSeoHeadTest extends TestCase
{
    private SeoStore $store;
    private ContentSeoHead $head;

    protected function setUp(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(
            'CREATE TABLE cms_content_seo (
                id TEXT PRIMARY KEY, tenant_id TEXT, ref TEXT NOT NULL, editor_id TEXT NOT NULL,
                title TEXT NOT NULL DEFAULT "", description TEXT NOT NULL DEFAULT "",
                og_title TEXT NOT NULL DEFAULT "", og_description TEXT NOT NULL DEFAULT "",
                og_image TEXT NOT NULL DEFAULT "", json_ld TEXT NOT NULL DEFAULT "",
                canonical TEXT NOT NULL DEFAULT "", robots TEXT NOT NULL DEFAULT "",
                authored_json TEXT NOT NULL DEFAULT "[]", source_hash TEXT NOT NULL DEFAULT "",
                due_at TEXT, attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL
            )',
        );

        $this->store = new SeoStore();
        (new \ReflectionProperty(SeoStore::class, 'orm'))->setValue($this->store, $orm);

        $this->head = new ContentSeoHead();
        (new \ReflectionProperty(ContentSeoHead::class, 'store'))->setValue($this->head, $this->store);

        // Both stores are request-scoped and neither is reset between tests by
        // anything else; a leaked title is what makes the never-overwrite cases
        // pass for the wrong reason.
        SeoMeta::reset();
        \Semitexa\Core\Support\CoroutineLocal::remove('cms.seo.applied');
    }

    protected function tearDown(): void
    {
        SeoMeta::reset();
        \Semitexa\Core\Support\CoroutineLocal::remove('cms.seo.applied');
    }

    /** @param array<string, string> $values */
    private function seed(string $ref, array $values): void
    {
        $this->store->saveAuthored($ref, 'demo:article', $values);
    }

    #[Test]
    public function what_the_cms_stored_reaches_the_meta_store(): void
    {
        $this->seed('demo:article:welcome', [
            'title' => 'Ласкаво просимо — Semitexa',
            'description' => 'Опис сторінки.',
            'ogTitle' => 'Ласкаво просимо',
            'ogDescription' => 'Опис для соцмереж.',
            'ogImage' => '/os/app/cms/media/cover',
            'robots' => 'index,follow',
        ]);

        $this->head->apply('demo:article:welcome');

        self::assertSame('Ласкаво просимо — Semitexa', SeoMeta::getTitle());
        self::assertSame('Опис сторінки.', SeoMeta::get('description'));
        self::assertSame('Ласкаво просимо', SeoMeta::get('og:title'));
        self::assertSame('Опис для соцмереж.', SeoMeta::get('og:description'));
        self::assertSame('/os/app/cms/media/cover', SeoMeta::get('og:image'));
        self::assertSame('index,follow', SeoMeta::get('robots'));
    }

    /**
     * The promise the meta layer makes one level up — a person's decision is
     * never replaced — has to hold at this level too, or it holds nowhere.
     */
    #[Test]
    public function a_page_that_already_said_something_keeps_saying_it(): void
    {
        $this->seed('demo:article:welcome', [
            'title' => 'Згенерований заголовок',
            'description' => 'Згенерований опис.',
        ]);

        SeoMeta::setTitle('Те, що вирішила сторінка');
        SeoMeta::setDefault('description', 'Опис, який вирішила сторінка.');

        $this->head->apply('demo:article:welcome');

        self::assertSame('Те, що вирішила сторінка', SeoMeta::getTitle());
        self::assertSame('Опис, який вирішила сторінка.', SeoMeta::get('description'));
    }

    /** The social title falls back to the page title rather than going missing. */
    #[Test]
    public function the_social_title_falls_back_to_the_plain_one(): void
    {
        $this->seed('demo:article:welcome', [
            'title' => 'Єдиний заголовок',
            'description' => 'Єдиний опис.',
        ]);

        $this->head->apply('demo:article:welcome');

        self::assertSame('Єдиний заголовок', SeoMeta::get('og:title'));
        self::assertSame('Єдиний опис.', SeoMeta::get('og:description'));
    }

    #[Test]
    public function the_canonical_and_the_structured_data_are_printed_here(): void
    {
        $this->seed('demo:article:welcome', [
            'canonical' => 'https://example.test/welcome',
            'jsonLd' => '{"@context":"https://schema.org","@type":"Article","headline":"Ласкаво просимо"}',
        ]);

        $this->head->apply('demo:article:welcome');
        $html = $this->head->extraTags();

        self::assertStringContainsString('<link rel="canonical" href="https://example.test/welcome">', $html);
        self::assertStringContainsString('"@type":"Article"', $html);
        self::assertStringContainsString('<script type="application/ld+json">', $html);
    }

    /**
     * A layout prints this on every page, so a page the CMS knows nothing about
     * must produce silence — not an empty canonical pointing at nowhere.
     */
    #[Test]
    public function a_page_the_cms_knows_nothing_about_prints_nothing(): void
    {
        $this->head->apply('demo:article:never-described');

        self::assertSame('', $this->head->extraTags());
        self::assertNull(SeoMeta::get('description'));
        self::assertSame('', SeoMeta::getTitle());
    }

    /** And so must a call that never happened — the layout still runs. */
    #[Test]
    public function nothing_is_printed_when_no_record_was_applied_at_all(): void
    {
        self::assertSame('', $this->head->extraTags());
    }

    /**
     * A graph cannot be allowed to close the element it sits in. Stored data is
     * validated on the way in, but this prints into a page and the cost of
     * being wrong here is the whole document.
     */
    #[Test]
    public function structured_data_cannot_break_out_of_its_script_element(): void
    {
        $this->seed('demo:article:welcome', [
            'jsonLd' => '{"@type":"Article","headline":"</script><script>alert(1)</script>"}',
        ]);

        $this->head->apply('demo:article:welcome');
        $html = $this->head->extraTags();

        self::assertStringNotContainsString('</script><script>', $html);
        self::assertStringContainsString('<', $html, 'the angle bracket is escaped, not stripped');
    }

    /** Structured data a crawler cannot parse is a liability; absence is not. */
    #[Test]
    public function structured_data_that_does_not_parse_is_dropped(): void
    {
        $this->seed('demo:article:welcome', ['jsonLd' => 'not json at all']);

        $this->head->apply('demo:article:welcome');

        self::assertStringNotContainsString('ld+json', $this->head->extraTags());
    }
}
