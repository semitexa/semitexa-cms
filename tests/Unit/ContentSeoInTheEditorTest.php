<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentEditorPage;
use Semitexa\Cms\Application\Service\SeoStore;
use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

/**
 * What the editor shows about a page's metadata, and why it shows it that way.
 *
 * The CMS promises never to overwrite what a person typed. The promise is only
 * real if an author can see which values are theirs — and the way this panel
 * makes that visible is also the mechanism that keeps the promise:
 *
 *   * a claimed value sits IN the field;
 *   * a generated one is the placeholder, and the field is empty.
 *
 * Empty is what the form posts on a save that had nothing to do with metadata,
 * and an empty value means «still the machine's». Render a generated value as a
 * value instead and the very next save claims all of it, silently, and the
 * generator never runs again. These cases pin that, not the wording.
 */
final class ContentSeoInTheEditorTest extends TestCase
{
    private SeoStore $store;
    private ContentEditorPage $page;

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

        $this->page = new ContentEditorPage();
        (new \ReflectionProperty(ContentEditorPage::class, 'seoStore'))->setValue($this->page, $this->store);
    }

    private function draft(string $ref = 'demo:article:welcome'): ContentDraft
    {
        return new ContentDraft(
            ref: $ref,
            title: 'Ласкаво просимо',
            fields: [
                ContentField::line('title', 'Заголовок', 'Ласкаво просимо', true),
                ContentField::html('body', 'Текст', '<div>Текст.</div>', true),
            ],
        );
    }

    private function render(string $ref = 'demo:article:welcome'): string
    {
        return $this->page->render($this->draft($ref), 'token');
    }

    /** The generator's work is visible, and legible as the generator's. */
    #[Test]
    public function a_generated_value_is_shown_as_a_placeholder_and_marked_generated(): void
    {
        $this->store->settle(
            $this->store->get('demo:article:welcome')->withGenerated(
                ['description' => 'Опис, написаний CMS.'],
                'hash-1',
            ),
            'demo:article',
            'hash-1',
        );

        $html = $this->render();

        self::assertStringContainsString('placeholder="Опис, написаний CMS."', $html);
        self::assertStringContainsString('згенеровано', $html);
    }

    /**
     * The load-bearing case. An empty field is what an unrelated save posts,
     * and empty means "still the machine's" — so a generated value must never
     * be rendered where the form would send it back as a claim.
     */
    #[Test]
    public function a_generated_value_is_never_rendered_where_the_form_would_claim_it(): void
    {
        $this->store->settle(
            $this->store->get('demo:article:welcome')->withGenerated(
                ['title' => 'Згенерований заголовок'],
                'hash-1',
            ),
            'demo:article',
            'hash-1',
        );

        $html = $this->render();

        self::assertStringNotContainsString('value="Згенерований заголовок"', $html);
        self::assertStringContainsString('placeholder="Згенерований заголовок"', $html);
    }

    /** What a person claimed is theirs, sits in the field, and says so. */
    #[Test]
    public function an_authored_value_is_shown_as_a_value_and_marked_as_the_authors(): void
    {
        $this->store->saveAuthored('demo:article:welcome', 'demo:article', [
            'title' => 'Мій заголовок',
        ]);

        $html = $this->render();

        self::assertStringContainsString('value="Мій заголовок"', $html);
        self::assertStringContainsString('ваше', $html);
    }

    /**
     * A field nothing generates has no second provenance to confuse, so it
     * carries its value plainly — a canonical address rendered as a placeholder
     * would be a value the author could not see they had set.
     */
    #[Test]
    public function a_field_the_generator_never_touches_carries_its_value_plainly(): void
    {
        $this->store->saveAuthored('demo:article:welcome', 'demo:article', [
            'canonical' => 'https://example.test/welcome',
        ]);

        self::assertStringContainsString('value="https://example.test/welcome"', $this->render());
    }

    #[Test]
    public function a_page_with_no_metadata_yet_says_so_rather_than_showing_nothing(): void
    {
        $html = $this->render();

        self::assertStringContainsString('ще не згенеровано', $html);
        self::assertStringContainsString('Для пошуку і соцмереж', $html);
    }

    /** Every offered field posts under the group the module never receives. */
    #[Test]
    public function the_fields_post_under_the_metadata_group(): void
    {
        $html = $this->render();

        foreach (['title', 'description', 'ogTitle', 'ogDescription', 'ogImage', 'canonical', 'robots'] as $field) {
            self::assertStringContainsString('name="seo[' . $field . ']"', $html, $field . ' must be offered');
        }
    }

    /**
     * A graph for machines is not a textarea for people. Offering it would be
     * offering a way to store structured data no crawler can parse.
     */
    #[Test]
    public function the_structured_data_graph_is_not_offered_as_a_field(): void
    {
        self::assertStringNotContainsString('name="seo[jsonLd]"', $this->render());
    }

    /**
     * The round trip, and the bug it was written from.
     *
     * The panel posts every field on every save, so an author fixing a comma in
     * the body posts blanks for all of the metadata. Before
     * ContentSeo::editorSubmission() existed those blanks reached the store and
     * emptied the whole description — permanently, because a save whose content
     * did not change SETTLES the debounce rather than restarting it, so nothing
     * ever wrote it back. Measured through the live form, not reasoned about.
     */
    #[Test]
    public function a_save_that_touched_no_metadata_leaves_the_generated_text_alone(): void
    {
        $seeded = $this->store->get('demo:article:welcome')->withGenerated(
            ['title' => 'Згенерований заголовок', 'description' => 'Згенерований опис.'],
            'hash-1',
        );
        $this->store->settle($seeded, 'demo:article', 'hash-1');

        // Exactly what the panel posts when nobody touched it.
        $posted = [
            'title' => '', 'description' => '', 'ogTitle' => '', 'ogDescription' => '',
            'ogImage' => '', 'canonical' => '', 'robots' => '',
        ];

        $current = $this->store->get('demo:article:welcome');
        $this->store->saveAuthored('demo:article:welcome', 'demo:article', $current->editorSubmission($posted));

        $after = $this->store->get('demo:article:welcome');

        self::assertSame('Згенерований заголовок', $after->title);
        self::assertSame('Згенерований опис.', $after->description);
        self::assertSame([], $after->authored, 'and none of it became the author\'s by accident');
    }

    /** Typing into one box claims that box, and only that box. */
    #[Test]
    public function typing_into_one_field_claims_only_that_field(): void
    {
        $seeded = $this->store->get('demo:article:welcome')->withGenerated(
            ['title' => 'Згенерований заголовок', 'description' => 'Згенерований опис.'],
            'hash-1',
        );
        $this->store->settle($seeded, 'demo:article', 'hash-1');

        $posted = ['title' => 'Мій заголовок', 'description' => '', 'ogTitle' => '',
                   'ogDescription' => '', 'ogImage' => '', 'canonical' => '', 'robots' => ''];

        $current = $this->store->get('demo:article:welcome');
        $this->store->saveAuthored('demo:article:welcome', 'demo:article', $current->editorSubmission($posted));

        $after = $this->store->get('demo:article:welcome');

        self::assertSame('Мій заголовок', $after->title);
        self::assertSame(['title'], $after->authored);
        self::assertSame('Згенерований опис.', $after->description, 'the untouched one is still the generator\'s');
    }

    /** Clearing a box you own is still how you hand the field back. */
    #[Test]
    public function clearing_a_field_you_own_hands_it_back_to_the_generator(): void
    {
        $this->store->saveAuthored('demo:article:welcome', 'demo:article', ['title' => 'Мій заголовок']);

        $posted = ['title' => '', 'description' => '', 'ogTitle' => '',
                   'ogDescription' => '', 'ogImage' => '', 'canonical' => '', 'robots' => ''];

        $current = $this->store->get('demo:article:welcome');
        $this->store->saveAuthored('demo:article:welcome', 'demo:article', $current->editorSubmission($posted));

        $after = $this->store->get('demo:article:welcome');

        self::assertSame('', $after->title);
        self::assertSame([], $after->authored);
        self::assertContains('title', $after->openToGeneration());
    }

    /**
     * An installation whose metadata store is not wired still opens its editor.
     * A console that will not load because a table is missing is a worse
     * failure than a console without the section.
     */
    #[Test]
    public function the_editor_opens_without_a_metadata_store(): void
    {
        $bare = new ContentEditorPage();

        $html = $bare->render($this->draft(), 'token');

        self::assertStringContainsString('Ласкаво просимо', $html);
        self::assertStringNotContainsString('seo[', $html);
    }
}
