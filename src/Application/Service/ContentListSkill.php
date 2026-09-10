<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\ContentRow;
use Semitexa\Cms\Domain\Model\Place;
use Semitexa\Weave\Domain\Enum\NodeKind;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Contract\InvocableSkillInterface;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * What of this site a person may edit, answered in a conversation.
 *
 * The CMS already knew all of it and told nobody: the map names the places and
 * {@see ContentCollectionInterface} pages the rows behind a collection, but the
 * only skill the package shipped was the editor, which opens a window and
 * answers nothing. So "which pages do we have?" had no answer, and neither did
 * the planner — it cannot propose opening a page whose ref it has never seen.
 *
 * Every line carries a ref, because a ref is the vocabulary everything
 * downstream takes: the editor skill, and each tenant's own `*:page:edit`.
 *
 * It answers rather than raising a window, so it is NOT on the `ui` channel.
 */
#[AsService]
#[AsAiSkill(
    name: 'content-list',
    summary: 'List the pages and collections of this site that can be edited.',
    useWhen: 'The user asks what content exists — "which pages do we have", "show me the events", "що в нас на сайті". Pass `collection` with a collection ref to page through its rows, `page` to move between pages, and `search` to narrow by title.',
    avoidWhen: 'They want to open one specific record for editing (use Content), or to change something.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['collection', 'search', 'page'],
    argumentHints: [
        'collection' => 'Ref of a collection to open, exactly as listed (e.g. "regmus:events"). Omit to list the whole map.',
        'search' => 'Narrow to titles containing this text.',
        'page' => 'Page number when a collection has more rows than fit at once.',
    ],
    channels: ['console', 'telegram', 'web'],
)]
final class ContentListSkill implements InvocableSkillInterface
{
    /** A conversation is not a grid: enough rows to recognise one, not to browse. */
    private const PER_PAGE = 20;

    #[InjectAsReadonly]
    protected SiteMapProjector $maps;

    #[InjectAsReadonly]
    protected ContentSurfaceRegistry $surfaces;

    public function invoke(array $arguments): string
    {
        $search = trim((string) ($arguments['search'] ?? ''));
        $collection = trim((string) ($arguments['collection'] ?? ''));

        return $collection === ''
            ? $this->listMap($search)
            : $this->listCollection($collection, $search, $this->pageOf($arguments));
    }

    /**
     * The places themselves — no row counts.
     *
     * Counting would mean one query per collection to answer a question nobody
     * asked; the map is a dozen places and stays cheap precisely because it does
     * not touch the records behind them.
     */
    private function listMap(string $search): string
    {
        return self::renderMap($this->maps->providers(), $search);
    }

    /**
     * Pure over the providers, so the shape of the answer is testable without
     * standing up discovery: SiteMapProviderInterface is an interface, the two
     * services that find them are not.
     *
     * @param list<\Semitexa\Cms\Domain\Contract\SiteMapProviderInterface> $providers
     */
    private static function renderMap(array $providers, string $search): string
    {
        if ($providers === []) {
            return 'This site has no content map, so there is nothing I can list. A site declares one with #[AsSiteMap].';
        }

        $lines = [];
        $shown = 0;
        $hidden = 0;

        foreach ($providers as $provider) {
            $pages = [];
            $collections = [];

            foreach ($provider->places() as $place) {
                // The site itself is the heading, not a line under it.
                if ($place->kind === NodeKind::Site) {
                    continue;
                }
                if ($search !== '' && !self::matches($place->title, $search)) {
                    $hidden++;
                    continue;
                }
                $shown++;
                if ($place->isCollection()) {
                    $collections[] = $place;
                } else {
                    $pages[] = $place;
                }
            }

            if ($pages === [] && $collections === []) {
                continue;
            }

            $lines[] = trim($provider->siteTitle()) !== '' ? $provider->siteTitle() : $provider->siteRef();
            foreach ([['Pages', $pages], ['Collections', $collections]] as [$heading, $group]) {
                if ($group === []) {
                    continue;
                }
                $lines[] = '  ' . $heading;
                foreach ($group as $place) {
                    $lines[] = '    ' . $place->ref . '  ' . $place->title;
                }
            }
        }

        if ($shown === 0) {
            return $search === ''
                ? 'The map has no pages or collections yet.'
                : "Nothing on the map matches \"{$search}\".";
        }

        if ($hidden > 0) {
            $lines[] = '';
            $lines[] = "Matching \"{$search}\"; {$hidden} other place(s) not shown.";
        }

        return implode("\n", $lines);
    }

    private function listCollection(string $ref, string $search, int $page): string
    {
        $place = $this->collectionPlace($ref);
        if ($place === null) {
            return "I don't know a collection called \"{$ref}\". Ask me to list the site's content to see the refs.";
        }

        $source = (string) $place->source;
        $collection = $this->surfaces->collection($source);
        if ($collection === null) {
            // The map named a source no module answers for. Saying so beats an
            // empty list, which reads as "there is nothing here".
            return "\"{$place->title}\" is on the map, but nothing in this site answers for its source ({$source}).";
        }

        return self::renderRows(
            $collection->rows(ContentSurfaceRegistry::filtersOf($source), $page, self::PER_PAGE),
            $search,
        );
    }

    /** Pure over an already-fetched page of rows — see {@see renderMap()}. */
    private static function renderRows(\Semitexa\Cms\Domain\Model\ContentRows $rows, string $search): string
    {
        $matched = $search === ''
            ? $rows->rows
            : array_values(array_filter(
                $rows->rows,
                static fn(ContentRow $row): bool => self::matches($row->title, $search),
            ));

        if ($matched === []) {
            if ($search !== '') {
                return "Nothing on page {$rows->page} of \"{$rows->title}\" matches \"{$search}\".";
            }

            // Empty and past-the-end are different facts. Saying "no records
            // yet" about a collection that holds four of them is the assistant
            // stating something false about the site, which is worse than
            // saying nothing — and asking for page 2 of 1 is an easy thing for
            // a planner to do.
            return $rows->total === 0
                ? "\"{$rows->title}\" has no records yet."
                : "\"{$rows->title}\" has {$rows->total} record(s) on {$rows->pages()} page(s); there is no page {$rows->page}.";
        }

        $lines = [$rows->title . ' — page ' . $rows->page . ' of ' . $rows->pages() . ', ' . $rows->total . ' record(s) in all.'];
        foreach ($matched as $row) {
            $meta = $row->meta === [] ? '' : '  (' . implode(', ', $row->meta) . ')';
            $lines[] = '  ' . $row->ref . '  ' . $row->title . $meta;
        }

        if ($search !== '') {
            // Say where the filter was applied. It narrowed THIS page, not the
            // collection: the source's own vocabulary is the module's, and
            // inventing a `search` filter for it would be putting words in its
            // mouth.
            $lines[] = '';
            $lines[] = "Filtered page {$rows->page} by \"{$search}\" — other pages may hold more.";
        } elseif ($rows->hasNext()) {
            $lines[] = '';
            $lines[] = 'Ask for page ' . ($rows->page + 1) . ' to see more.';
        }

        return implode("\n", $lines);
    }

    private function collectionPlace(string $ref): ?Place
    {
        foreach ($this->maps->providers() as $provider) {
            foreach ($provider->places() as $place) {
                if ($place->isCollection() && $place->ref === $ref) {
                    return $place;
                }
            }
        }

        return null;
    }

    /** @param array<string, scalar|null> $arguments */
    private function pageOf(array $arguments): int
    {
        return max(1, (int) ($arguments['page'] ?? 1));
    }

    /** Case-insensitive and accent-naive on purpose: a person typing into chat. */
    private static function matches(string $title, string $needle): bool
    {
        return mb_stripos($title, $needle) !== false;
    }
}
