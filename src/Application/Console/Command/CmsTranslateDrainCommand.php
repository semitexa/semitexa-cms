<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Console\Command;

use Semitexa\Cms\Application\Service\TranslationDrain;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Translate what has been waiting long enough.
 *
 * For installs with no scheduler running: put it on cron every few minutes. Safe
 * to run twice — a settled record is not due, and a record edited since is
 * translated from its current text.
 *
 * Runs in the ambient tenant, like the map build: the queue is per-site.
 */
#[AsCommand(
    name: 'cms:translate:drain',
    description: 'Translate content whose debounce window has closed.',
)]
final class CmsTranslateDrainCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected TranslationDrain $drain;

    protected function configure(): void
    {
        $this->setName('cms:translate:drain')
            ->setDescription('Translate content whose debounce window has closed.')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'How many records at most', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->drain->drain(max(1, (int) $input->getOption('batch')));

        $output->writeln(sprintf(
            'Translated %d · failed %d · dropped %d',
            $report['done'],
            $report['failed'],
            $report['gone'],
        ));

        return Command::SUCCESS;
    }
}
