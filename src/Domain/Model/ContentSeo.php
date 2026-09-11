<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/**
 * A page's meta layer, split by who wrote it.
 *
 * The split is the whole design. Everything here can be generated from the
 * page's own content, and everything here can also be a deliberate decision
 * someone made — and those two must never be confused. A description an editor
 * agonised over, silently replaced the next time the page is saved, is worse
 * than having no generator at all: it teaches people not to touch the field.
 *
 * So a field is generated only while nobody has claimed it. {@see authored}
 * names the fields a person set; those are returned as-is and the writer is
 * told to leave them alone. Clearing an authored field hands it back to the
 * generator, which is the only way back.
 *
 * `canonical` and `robots` are never generated. They are structural decisions
 * about how a site wants to be indexed, not observations about the text, and a
 * model guessing at them would be inventing policy.
 */
final readonly class ContentSeo
{
    /** Google truncates around here; longer is not wrong, just unread. */
    public const DESCRIPTION_LIMIT = 160;

    public const TITLE_LIMIT = 70;

    /** The fields a writer may produce. Anything else is a person's decision. */
    public const GENERATED_FIELDS = ['title', 'description', 'ogTitle', 'ogDescription', 'jsonLd'];

    /**
     * The fields the editor puts on the form, and therefore the only ones a
     * submission may set.
     *
     * `jsonLd` is deliberately absent: it is built from the record, there is no
     * control for it, and nobody types it. That made it the one field a crafted
     * post could reach unopposed — `seo[jsonLd]=…` went through
     * {@see editorSubmission()} like any other key, and the value it set was
     * then marked AUTHORED, which is permanent in effect: generation leaves an
     * authored field alone, so the record would carry that block for as long as
     * it existed and nothing on the page would explain where it came from.
     *
     * Read by the form that renders these fields and by the request that
     * accepts them, so the two cannot disagree about what the editor owns.
     */
    public const EDITOR_FIELDS = ['title', 'description', 'ogTitle', 'ogDescription', 'ogImage', 'canonical', 'robots'];

    /**
     * @param list<string> $authored names of fields a person set by hand
     */
    public function __construct(
        public string $ref,
        public string $title = '',
        public string $description = '',
        public string $ogTitle = '',
        public string $ogDescription = '',
        public string $ogImage = '',
        public string $jsonLd = '',
        public string $canonical = '',
        public string $robots = '',
        public array $authored = [],
        public string $sourceHash = '',
    ) {}

    public function isAuthored(string $field): bool
    {
        return in_array($field, $this->authored, true);
    }

    /** True when nothing has been written yet — the page has no meta at all. */
    public function isEmpty(): bool
    {
        return $this->title === ''
            && $this->description === ''
            && $this->ogTitle === ''
            && $this->ogDescription === ''
            && $this->jsonLd === '';
    }

    /**
     * The fields a generator is still allowed to fill for this page.
     *
     * @return list<string>
     */
    public function openToGeneration(): array
    {
        return array_values(array_filter(
            self::GENERATED_FIELDS,
            fn (string $field): bool => !$this->isAuthored($field),
        ));
    }

    /**
     * Take a generator's output, keeping every field a person claimed.
     *
     * @param array<string, string> $generated field name => value
     */
    public function withGenerated(array $generated, string $sourceHash): self
    {
        $take = fn (string $field, string $current): string => $this->isAuthored($field)
            ? $current
            : trim((string) ($generated[$field] ?? $current));

        return new self(
            ref: $this->ref,
            title: $take('title', $this->title),
            description: $take('description', $this->description),
            ogTitle: $take('ogTitle', $this->ogTitle),
            ogDescription: $take('ogDescription', $this->ogDescription),
            // Never generated: an image is a choice about the page, and these
            // two are indexing policy.
            ogImage: $this->ogImage,
            jsonLd: $take('jsonLd', $this->jsonLd),
            canonical: $this->canonical,
            robots: $this->robots,
            authored: $this->authored,
            sourceHash: $sourceHash,
        );
    }

    /**
     * Read a submission from the editor panel, where a blank box has two
     * different meanings depending on who owns the field.
     *
     * The panel posts every metadata field on every save, and it renders a
     * GENERATED value as a placeholder with the box empty — because an empty
     * box is what must be sent back when the author did not touch the section,
     * and a generated value rendered as a value would be claimed by the very
     * next save.
     *
     * Which makes a blank ambiguous, and the owner resolves it:
     *
     *   * on a field the author OWNS, a blank is them handing it back, and
     *     {@see withAuthored()} clears the value and releases the claim;
     *   * on a field they do not own, a blank is just the box the placeholder
     *     was drawn in. It says nothing, so it is dropped here — passing it on
     *     would erase the generated text, and since a save with unchanged
     *     content settles the debounce rather than restarting it, nothing would
     *     ever write it back.
     *
     * That last part is not hypothetical: it was measured through the live form
     * before this method existed. One save with the panel untouched emptied a
     * page's whole description, permanently.
     *
     * @param array<string, string> $values as posted
     *
     * @return array<string, string> ready for {@see withAuthored()}
     */
    public function editorSubmission(array $values): array
    {
        foreach (self::GENERATED_FIELDS as $field) {
            if (array_key_exists($field, $values) && trim($values[$field]) === '' && !$this->isAuthored($field)) {
                unset($values[$field]);
            }
        }

        return $values;
    }

    /**
     * Take what a person typed. A value they cleared is released back to the
     * generator rather than pinned as a deliberate empty string — otherwise
     * emptying a field would silently switch the generator off for good.
     *
     * @param array<string, string> $values field name => value
     */
    public function withAuthored(array $values): self
    {
        $authored = $this->authored;
        $next = [];

        foreach (self::GENERATED_FIELDS as $field) {
            if (!array_key_exists($field, $values)) {
                $next[$field] = $this->{$field};
                continue;
            }
            $value = trim($values[$field]);
            $next[$field] = $value;
            $authored = array_values(array_diff($authored, [$field]));
            if ($value !== '') {
                $authored[] = $field;
            }
        }

        sort($authored);

        return new self(
            ref: $this->ref,
            title: $next['title'],
            description: $next['description'],
            ogTitle: $next['ogTitle'],
            ogDescription: $next['ogDescription'],
            ogImage: array_key_exists('ogImage', $values) ? trim($values['ogImage']) : $this->ogImage,
            jsonLd: $next['jsonLd'],
            canonical: array_key_exists('canonical', $values) ? trim($values['canonical']) : $this->canonical,
            robots: array_key_exists('robots', $values) ? trim($values['robots']) : $this->robots,
            authored: $authored,
            sourceHash: $this->sourceHash,
        );
    }

    /** True when the content has moved on since the meta was generated. */
    public function isStale(string $currentHash): bool
    {
        return $this->sourceHash !== '' && $this->sourceHash !== $currentHash;
    }
}
