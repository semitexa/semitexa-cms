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
 */
#[AsAiSkill(
    name: 'Content',
    summary: 'Open a page of the site for editing.',
    useWhen: 'The user wants to change the text of a specific page — its title, description or body.',
    avoidWhen: 'They want to create or delete something; those are the site module\'s own skills.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['ui'],
    icon: 'file-pen',
    entry: '/os/app/cms',
)]
final class ContentEditorSkill
{
}
