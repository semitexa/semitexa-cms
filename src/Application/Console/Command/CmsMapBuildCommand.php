<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Console\Command;

use Semitexa\Cms\Application\Service\SiteMapProjector;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Build (or refresh) the map of every site this install serves.
 *
 * Safe to re-run: places are identified by their record, so a rebuild after new
 * content appears updates what changed and leaves alone what a person has since
 * edited.
 */
#[AsCommand(
    name: 'cms:map:build',
    description: 'Project each site\'s map of places into the graph.',
)]
final class CmsMapBuildCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected SiteMapProjector $projector;

    protected function configure(): void
    {
        $this->setName('cms:map:build')
            ->setDescription('Project each site\'s map of places into the graph.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be written, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $reports = $this->projector->projectAll($dryRun);

        $skipped = $this->projector->skippedTenants();

        if ($reports === []) {
            if ($skipped !== []) {
                // The likeliest mistake by far: running the build with no
                // tenant context and wondering why the console shows nothing.
                $output->writeln(sprintf(
                    '<comment>Nothing built here (tenant: %s). Maps are declared for: %s — run e.g. `tenant:run %s cms:map:build`.</comment>',
                    $this->projector->currentTenantId(),
                    implode(', ', $skipped),
                    $skipped[0],
                ));

                return Command::SUCCESS;
            }

            $output->writeln('<comment>No site map declared. A module describes its own map with #[AsSiteMap].</comment>');

            return Command::SUCCESS;
        }

        foreach ($reports as $report) {
            $output->writeln(sprintf(
                '%s <info>%s</info>: %d place(s), %d edge(s)',
                $dryRun ? 'Would build' : 'Built',
                $report['site'],
                $report['places'],
                $report['edges'],
            ));

            if ($report['stale'] !== []) {
                // Not deleted on purpose — see SiteMapProjector::staleRefs().
                $output->writeln(sprintf(
                    '  <comment>%d place(s) no longer in the map, left in place: %s</comment>',
                    count($report['stale']),
                    implode(', ', $report['stale']),
                ));
            }
        }

        if ($skipped !== []) {
            $output->writeln(sprintf(
                '<comment>Skipped map(s) belonging to: %s. Build each in its own tenant.</comment>',
                implode(', ', $skipped),
            ));
        }

        return Command::SUCCESS;
    }
}
