<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Model;

/** One translated field of one record in one language, as the model wrote it. */
final readonly class TranslatedValue
{
    public function __construct(
        public string $locale,
        public string $field,
        public string $value,
        /** Markup: the CMS sanitises it before it is handed back to be stored. */
        public bool $html = false,
    ) {}

    public function withValue(string $value): self
    {
        return new self($this->locale, $this->field, $value, $this->html);
    }
}
