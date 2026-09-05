<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\SeoStore;
use Semitexa\Cms\Domain\Model\ContentSeo;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

/**
 * The deferral, which is the point of the queue.
 *
 * People edit in bursts — save, reread, fix a comma, save again. Writing the
 * metadata on the save itself would buy a model call per correction and publish
 * a description of a page that was already being changed again. The deadline
 * slides instead, so what gets described is the text they settled on.
 */
final class SeoDebounceTest extends TestCase
{
    private SeoStore $store;

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
    }

    #[Test]
    public function a_save_does_not_describe_the_page_it_only_sets_a_deadline(): void
    {
        $this->store->touch('page:about', 'museum:page', 'hash-1');

        self::assertTrue($this->store->get('page:about')->isEmpty(), 'nothing may be written on the save itself');
        self::assertSame([], $this->store->due(10), 'and nothing is due until the window closes');
    }

    /**
     * The case this exists for: ten corrections in a row must cost one
     * description, not ten. Each save pushes the deadline out, so the page is
     * only ever due once — after the editing stops.
     */
    #[Test]
    public function a_burst_of_corrections_leaves_exactly_one_page_due(): void
    {
        foreach (range(1, 10) as $edit) {
            $this->store->touch('page:about', 'museum:page', 'hash-' . $edit);
            self::assertSame([], $this->store->due(10), "edit {$edit} must not become due while typing continues");
        }

        $due = $this->store->due(10, $this->afterTheWindow());

        self::assertCount(1, $due);
        self::assertSame('page:about', $due[0]->ref);
    }

    #[Test]
    public function the_deadline_moves_with_each_save_rather_than_standing_still(): void
    {
        $this->store->touch('page:about', 'museum:page', 'hash-1', delayMinutes: 0);
        self::assertCount(1, $this->store->due(10), 'a zero window is due at once');

        $this->store->touch('page:about', 'museum:page', 'hash-2');
        self::assertSame([], $this->store->due(10), 'a further edit pushes it back out of reach');
    }

    /**
     * A word changed and changed back is the same page. Settling it for nothing
     * is what keeps a long editing session cheap.
     */
    #[Test]
    public function content_edited_back_to_what_was_already_described_owes_nothing(): void
    {
        $described = (new ContentSeo(ref: 'page:about'))->withGenerated(['description' => 'as it stands'], 'hash-1');
        $this->store->settle($described, 'museum:page', 'hash-1');

        $this->store->touch('page:about', 'museum:page', 'hash-1');

        self::assertSame([], $this->store->due(10, $this->afterTheWindow()), 'the same text must not buy a second call');
    }

    #[Test]
    public function content_that_really_changed_becomes_due_again(): void
    {
        $described = (new ContentSeo(ref: 'page:about'))->withGenerated(['description' => 'as it stands'], 'hash-1');
        $this->store->settle($described, 'museum:page', 'hash-1');

        $this->store->touch('page:about', 'museum:page', 'hash-2');

        self::assertCount(1, $this->store->due(10, $this->afterTheWindow()));
    }

    /**
     * A save that landed while the model was still writing must leave the page
     * due, or the site would keep a description of a version nobody wrote.
     */
    #[Test]
    public function a_save_during_generation_leaves_the_page_due(): void
    {
        $written = (new ContentSeo(ref: 'page:about'))->withGenerated(['description' => 'about the old text'], 'hash-1');

        $this->store->settle($written, 'museum:page', 'hash-2');

        self::assertCount(1, $this->store->due(10, $this->afterTheWindow()));
    }

    #[Test]
    public function what_a_person_wrote_is_stored_without_scheduling_another_look(): void
    {
        $seo = $this->store->saveAuthored('page:about', 'museum:page', ['description' => 'ours, thank you']);

        self::assertSame('ours, thank you', $seo->description);
        self::assertTrue($seo->isAuthored('description'));
        self::assertSame('ours, thank you', $this->store->get('page:about')->description);
        self::assertSame([], $this->store->due(10, $this->afterTheWindow()), 'their words are the answer, not a new question');
    }

    #[Test]
    public function repeated_failures_back_off_and_then_give_up(): void
    {
        $this->store->touch('page:about', 'museum:page', 'hash-1', delayMinutes: 0);

        for ($attempt = 1; $attempt < SeoStore::MAX_ATTEMPTS; $attempt++) {
            $this->store->fail('page:about', 'provider unreachable');
            self::assertSame([], $this->store->due(10), 'a failure backs off rather than retrying at once');
            self::assertCount(1, $this->store->due(10, $this->afterTheWindow(45)), "attempt {$attempt} should come back");
        }

        $this->store->fail('page:about', 'provider unreachable');
        self::assertSame([], $this->store->due(10, $this->afterTheWindow(60 * 24)), 'a dead provider must not spin forever');
    }

    #[Test]
    public function a_page_that_is_gone_is_forgotten_rather_than_retried(): void
    {
        $this->store->touch('page:about', 'museum:page', 'hash-1', delayMinutes: 0);
        self::assertCount(1, $this->store->due(10));

        $this->store->forget('page:about');

        self::assertSame([], $this->store->due(10));
        self::assertTrue($this->store->get('page:about')->isEmpty());
    }

    private function afterTheWindow(int $minutes = 0): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify('+' . ($minutes > 0 ? $minutes : SeoStore::DEBOUNCE_MINUTES + 1) . ' minutes');
    }
}
