<?php

declare(strict_types=1);

namespace Semitexa\Cms\Domain\Contract;

/**
 * How a module keeps a record's other languages in step.
 *
 * The CMS never translates anything: it does not know which languages a site
 * offers, where the overlay is stored, or what "the same text" means for that
 * module. It knows only when a record was touched, and it holds the debounce so
 * an editor's ten small corrections cost one translation rather than ten.
 */
interface ContentTranslatorInterface
{
    /** Matches the editor whose records this translates, e.g. 'regmus:page'. */
    public function translatorId(): string;

    /**
     * A hash of everything that would change the translation, or null when the
     * record is gone or has nothing translatable yet.
     *
     * It is what makes the queue cheap: text edited and then edited back settles
     * without spending a call, and a retry knows whether it is retrying the same
     * text or a newer one.
     */
    public function fingerprint(string $ref): ?string;

    /**
     * Bring every other language up to date with the current text.
     *
     * @throws \RuntimeException when the work could not be done and is worth retrying
     */
    public function translate(string $ref): void;
}
