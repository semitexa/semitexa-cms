<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * The editor as a UI-skill: identity and an entry route, nothing to run.
 *
 * It exists so a page on the map can be opened the same way every other console
 * surface is — as a dialog in Focus, raised by the shell with the record's ref
 * appended to the entry.
 *
 * It used to declare no arguments at all, which meant a ref could reach the
 * editor only from a map click: "open Contacts" raised the window on its empty
 * state, because chat had no way to say WHICH page. It now takes a name — what
 * a person actually says — and a ref for when the caller already has one.
 * The handler resolves the name against the map.
 */
#[AsAiSkill(
    name: 'Content',
    summary: 'Open a page of the site for editing.',
    useWhen: 'The user wants to change the text of a specific page — its title, description or body. Put the page as they named it in `name` (e.g. "Contacts", "Про музей"); pass `ref` instead only when you already have an exact ref such as "regmus:page:7", which content-list returns.',
    avoidWhen: 'They want to create or delete something; those are the site module\'s own skills. To find out WHAT pages exist, use content-list.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::Allowlisted,
    exposeArguments: ['name', 'ref'],
    argumentHints: [
        'name' => 'The page as the person named it, in their own words.',
        'ref' => 'Exact ref, e.g. "regmus:page:7" — only when you already have one.',
    ],
    channels: ['ui'],
    icon: 'file-pen',
    entry: '/os/app/cms',
)]
final class ContentEditorSkill
{
}
