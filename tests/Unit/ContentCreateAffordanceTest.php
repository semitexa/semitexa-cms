<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Service\ContentEditorPage;
use Semitexa\Cms\Domain\Model\ContentRow;
use Semitexa\Cms\Domain\Model\ContentRows;

/**
 * Publishing did not require a developer for the WORDS, only for the existence
 * of the thing being worded: an author could edit every page of a site and not
 * add one.
 *
 * The affordance has to appear exactly where the capability does. A button that
 * answers with a refusal is worse than no button — it teaches the author that
 * the console is unreliable rather than that this list is read-only.
 */
final class ContentCreateAffordanceTest extends TestCase
{
    private function rows(): ContentRows
    {
        return new ContentRows('Статті', [new ContentRow('demo:article:one', 'Перша')], total: 1);
    }

    #[Test]
    public function a_collection_that_can_author_offers_it(): void
    {
        $html = (new ContentEditorPage())->renderRows($this->rows(), 'demo:articles', 'tok-123', true);

        $this->assertStringContainsString('action="/os/app/cms/create"', $html);
        $this->assertStringContainsString('value="demo:articles"', $html);
        // The route writes, so it is a POST and it carries the token — without
        // it the console answers its own UI with a 403.
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('name="_csrf" value="tok-123"', $html);
    }

    #[Test]
    public function a_read_only_collection_offers_nothing(): void
    {
        // No token means the module does not implement the creator contract, so
        // the console must not hint that it does.
        $html = (new ContentEditorPage())->renderRows($this->rows(), 'demo:articles', '', false);

        $this->assertStringNotContainsString('/os/app/cms/create', $html);
    }

    #[Test]
    public function the_chooser_is_not_a_collection_and_gets_no_button(): void
    {
        // The chooser renders through the same view with an empty ref; a create
        // form there would post a ref naming no collection at all.
        $html = (new ContentEditorPage())->renderRows($this->rows(), '', 'tok-123', true);

        $this->assertStringNotContainsString('/os/app/cms/create', $html);
    }

    #[Test]
    public function creating_and_removing_are_offered_independently(): void
    {
        $page = new ContentEditorPage();

        // A module may offer either, both or neither. Handing the remover a
        // create button — which the first version of this did, because both
        // hung off the same token — is the refusal-answering button the whole
        // contract exists to avoid.
        $removeOnly = $page->renderRows($this->rows(), 'demo:articles', 'tok', false, true);
        $this->assertStringNotContainsString('/os/app/cms/create', $removeOnly);
        $this->assertStringContainsString('/os/app/cms/delete', $removeOnly);

        $createOnly = $page->renderRows($this->rows(), 'demo:articles', 'tok', true, false);
        $this->assertStringContainsString('/os/app/cms/create', $createOnly);
        $this->assertStringNotContainsString('/os/app/cms/delete', $createOnly);

        $neither = $page->renderRows($this->rows(), 'demo:articles', 'tok', false, false);
        $this->assertStringNotContainsString('/os/app/cms/create', $neither);
        $this->assertStringNotContainsString('/os/app/cms/delete', $neither);
    }

    #[Test]
    public function a_rows_delete_control_posts_rather_than_links(): void
    {
        // A GET that writes can be followed by a crawler or a browser prefetch.
        $html = (new ContentEditorPage())->renderRows($this->rows(), 'demo:articles', 'tok', false, true);

        $this->assertStringContainsString('<form class="rm" method="post"', $html);
        $this->assertStringNotContainsString('href="/os/app/cms/delete', $html);
    }

    #[Test]
    public function the_confirmation_names_the_record_and_promises_only_what_it_can(): void
    {
        $html = (new ContentEditorPage())->renderConfirmRemoval('demo:article:x', 'Весняна виставка', 'demo:articles', 'tok');

        // By name, not by ref: a row's delete control sits next to its title,
        // which is exactly where a misclick lives.
        $this->assertStringContainsString('Прибрати «Весняна виставка»?', $html);
        $this->assertStringContainsString('name="confirm" value="1"', $html);
        $this->assertStringContainsString('Скасувати', $html);

        // The CMS never touches the table, so it must not claim the bytes are
        // gone — only that the item stops being on the site.
        $this->assertStringContainsString('зникне з сайту', $html);
        $this->assertStringContainsString('може лишити копію', $html);
    }

    #[Test]
    public function a_module_that_refuses_says_why_in_its_own_words(): void
    {
        // The module is the only party that knows the reason, and an author told
        // "не вдалося" learns nothing.
        $html = (new ContentEditorPage())->renderCreateFailed('demo:articles', 'Квота вичерпана.');

        $this->assertStringContainsString('Квота вичерпана.', $html);
        $this->assertStringContainsString('demo:articles', $html);
    }

    #[Test]
    public function a_list_the_module_does_not_author_says_so(): void
    {
        $html = (new ContentEditorPage())->renderCannotCreate('demo:articles');

        $this->assertStringContainsString('не можна створити', $html);
        $this->assertStringNotContainsString('видалено', $html);
    }
}
