<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Console\Command;

use Semitexa\Cms\Application\Service\SeoDrain;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Write the metadata for pages that have been still long enough.
 *
 * For installs with no scheduler running: put it on cron every few minutes.
 * Safe to run twice — a page described from its current text is not due, and a
 * page edited since is described from what it says now.
 *
 * Runs in the ambient tenant, like the map build: the rows are per-site.
 */
#[AsCommand(
    name: 'cms:seo:drain',
    description: 'Write metadata for content whose debounce window has closed.',
)]
final class CmsSeoDrainCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected SeoDrain $drain;

    protected function configure(): void
    {
        $this->setName('cms:seo:drain')
            ->setDescription('Write metadata for content whose debounce window has closed.')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'How many pages at most', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->drain->drain(max(1, (int) $input->getOption('batch')));

        $output->writeln(sprintf(
            'Described %d · unchanged %d · failed %d · dropped %d',
            $report['done'],
            $report['skipped'],
            $report['failed'],
            $report['gone'],
        ));

        return Command::SUCCESS;
    }
}
