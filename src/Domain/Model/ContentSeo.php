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
