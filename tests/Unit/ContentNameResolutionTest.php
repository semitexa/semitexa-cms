<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Handler\PayloadHandler\ContentEditorHandler;
use Semitexa\Cms\Application\Service\ContentAnswerPage;
use Semitexa\Cms\Domain\Model\ContentRows;
use Semitexa\Weave\Domain\Contract\GraphStoreInterface;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Weave\Domain\Model\Node;

/**
 * "Open Contacts" has to reach the page called Contacts.
 *
 * It could not: the editor took a ref and nothing else, so a ref arrived only
 * from a map click and chat raised the window on its empty state — «Немає чого
 * відкрити для «»», which does not even say what was looked for.
 *
 * A name is a guess and a ref is exact, so the resolution has three outcomes
 * and each one has to stay distinguishable.
 */
final class ContentNameResolutionTest extends TestCase
{
    private function node(string $ref, string $title, NodeKind $kind = NodeKind::Page): Node
    {
        return new Node(
            id: 'n_' . md5($ref),
            kind: $kind,
            title: $title,
            properties: [],
            source: 'cms.sitemap',
            ref: $ref,
        );
    }

    /** @param list<Node> $nodes */
    private function handlerOver(array $nodes): ContentEditorHandler
    {
        $graph = new class ($nodes) implements GraphStoreInterface {
            public function __construct(private array $nodes) {}

            public function graph(int $limit = 500, ?array $kinds = null): array
            {
                return ['nodes' => $this->nodes, 'edges' => []];
            }

            public function upsertNode(NodeKind $kind, string $title, array $properties = [], string $source = ''): Node { throw new \LogicException('unused'); }
            public function upsertNodeByRef(NodeKind $kind, string $ref, string $title, array $properties = [], string $source = ''): Node { throw new \LogicException('unused'); }
            public function nodeByRef(string $ref): ?Node { return null; }
            public function addEdge(string $fromId, string $toId, string $relation, int $weight = 100, string $source = ''): \Semitexa\Weave\Domain\Model\Edge { throw new \LogicException('unused'); }
            public function updateNode(string $id, ?string $title = null, array $properties = []): ?Node { return null; }
            public function node(string $id): ?Node { return null; }
            public function nodesByKind(NodeKind $kind, int $limit = 0): array { return []; }
            public function search(string $term, int $limit = 20): array { return []; }
            // The contract is {node, edges, neighbors} — not the {nodes, edges}
            // shape graph() and subgraph() return. A fake that answers with the
            // wrong keys is a fake that would keep passing after the real store
            // changed, which is the one thing it exists not to do.
            public function neighborhood(string $nodeId): array { return ['node' => null, 'edges' => [], 'neighbors' => []]; }
            public function subgraph(string $nodeId, int $depth = 1): array { return ['nodes' => [], 'edges' => []]; }
            public function mergeNodes(string $keepId, string $dropId): void {}
            public function removeNode(string $id): void {}
            public function removeEdge(string $id): void {}
            public function counts(): array { return ['nodes' => count($this->nodes), 'edges' => 0]; }
        };

        $handler = (new \ReflectionClass(ContentEditorHandler::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ContentEditorHandler::class, 'graph'))->setValue($handler, $graph);

        return $handler;
    }

    private function resolve(ContentEditorHandler $handler, string $name): string|ContentRows|null
    {
        return (new \ReflectionMethod(ContentEditorHandler::class, 'resolveName'))->invoke($handler, $name);
    }

    #[Test]
    public function one_match_resolves_to_its_ref(): void
    {
        $handler = $this->handlerOver([
            $this->node('regmus:page:7', 'Контакти'),
            $this->node('regmus:page:9', 'Відвідування'),
        ]);

        $this->assertSame('regmus:page:7', $this->resolve($handler, 'Контакти'));
    }

    #[Test]
    public function a_partial_name_is_enough_when_it_names_one_thing(): void
    {
        $handler = $this->handlerOver([$this->node('regmus:page:7', 'Про музей')]);

        $this->assertSame('regmus:page:7', $this->resolve($handler, 'музей'));
    }

    #[Test]
    public function an_exact_title_wins_over_the_pages_that_merely_contain_it(): void
    {
        // A site with 'Contacts' and 'Contacts (old)' must not force a choice on
        // someone who named one of them precisely.
        $handler = $this->handlerOver([
            $this->node('regmus:page:7', 'Contacts'),
            $this->node('regmus:page:8', 'Contacts (old)'),
        ]);

        $this->assertSame('regmus:page:7', $this->resolve($handler, 'contacts'));
    }

    #[Test]
    public function several_candidates_come_back_as_a_list_to_pick_from(): void
    {
        $handler = $this->handlerOver([
            $this->node('regmus:page:7', 'Виставка весни'),
            $this->node('regmus:events', 'Виставки', NodeKind::Collection),
        ]);

        $rows = $this->resolve($handler, 'вистав');

        $this->assertInstanceOf(ContentRows::class, $rows);
        $this->assertSame(2, $rows->total);
        $this->assertSame(['regmus:page:7', 'regmus:events'], array_map(static fn($r) => $r->ref, $rows->rows));
        // Each candidate says what it is, so the choice is informed rather than
        // two titles that happen to look alike.
        $this->assertSame(['сторінка'], $rows->rows[0]->meta);
        $this->assertSame(['список'], $rows->rows[1]->meta);
    }

    #[Test]
    public function a_name_that_matches_nothing_resolves_to_nothing(): void
    {
        $handler = $this->handlerOver([$this->node('regmus:page:7', 'Контакти')]);

        $this->assertNull($this->resolve($handler, 'кулінарія'));
    }

    #[Test]
    public function an_empty_name_is_not_a_search(): void
    {
        // Otherwise every ref-less open would match the whole map.
        $handler = $this->handlerOver([$this->node('regmus:page:7', 'Контакти')]);

        $this->assertNull($this->resolve($handler, ''));
    }

    #[Test]
    public function opening_with_nothing_chosen_offers_the_map_instead_of_blaming_a_deletion(): void
    {
        // The empty state used to say "можливо, запис видалено" here. Nothing
        // had been asked for — the dialog was simply raised before anything was
        // chosen — so it accused the author of removing a record they had never
        // named.
        $handler = $this->handlerOver([
            $this->node('regmus:page:7', 'Контакти'),
            $this->node('regmus:events', 'Події', NodeKind::Collection),
        ]);
        (new \ReflectionProperty(ContentEditorHandler::class, 'answers'))->setValue($handler, new ContentAnswerPage());

        $html = (string) (new \ReflectionMethod(ContentEditorHandler::class, 'chooseAPlace'))->invoke($handler);

        $this->assertStringNotContainsString('видалено', $html);
        $this->assertStringContainsString('/os/app/cms?ref=regmus%3Apage%3A7', $html);
        $this->assertStringContainsString('/os/app/cms?ref=regmus%3Aevents', $html);
    }

    #[Test]
    public function a_site_with_no_map_is_told_how_to_build_one(): void
    {
        $handler = $this->handlerOver([]);
        (new \ReflectionProperty(ContentEditorHandler::class, 'answers'))->setValue($handler, new ContentAnswerPage());

        $html = (string) (new \ReflectionMethod(ContentEditorHandler::class, 'chooseAPlace'))->invoke($handler);

        $this->assertStringNotContainsString('видалено', $html);
        $this->assertStringContainsString('cms:map:build', $html);
    }

    #[Test]
    public function only_a_ref_that_names_nothing_may_suggest_a_deletion(): void
    {
        $answers = new ContentAnswerPage();

        // A ref resolving to nothing really can be a removed record.
        $this->assertStringContainsString('видалено', $answers->renderMissing('regmus:page:404'));

        // A name that matches nothing is usually a name this site never used.
        // Saying the work may have been deleted because someone typed
        // "Contacts" instead of "Kontakty" is the same accusation relocated.
        $notFound = $answers->renderNameNotFound('кулінарія');
        $this->assertStringNotContainsString('видалено', $notFound);
        $this->assertStringContainsString('кулінарія', $notFound);

        $this->assertStringNotContainsString('видалено', $answers->renderNothingChosen());
    }

    #[Test]
    public function a_node_without_a_ref_can_never_be_opened(): void
    {
        // A node with no ref names nothing the editor could load; matching it
        // would resolve a name to an empty ref, which reads as the empty state.
        $handler = $this->handlerOver([
            new Node(id: 'n1', kind: NodeKind::Page, title: 'Контакти', properties: [], source: 'x', ref: null),
        ]);

        $this->assertNull($this->resolve($handler, 'Контакти'));
    }
}
