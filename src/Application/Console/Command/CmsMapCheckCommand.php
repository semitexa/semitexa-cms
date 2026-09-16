<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Console\Command;

use Semitexa\Cms\Application\Service\ContentSurfaceRegistry;
use Semitexa\Cms\Application\Service\SiteMapDiff;
use Semitexa\Cms\Application\Service\SiteMapIntegrity;
use Semitexa\Cms\Application\Service\SiteMapProjector;
use Semitexa\Cms\Domain\Model\MapChangeRecord;
use Semitexa\Cms\Domain\Model\PlaceCheck;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ask, of every place on every map, whether the console can open it.
 *
 * WRITTEN AFTER A SITE'S OWNER FOUND IT FIRST. A museum's whole structure
 * branch was missing after a move, and one page would not open; both were
 * reported by the person whose site it is, because the move had reported
 * success and success meant a row count. {@see \Semitexa\Cms\Domain\Model\Place}
 * says in its own docblock that this site has 41 page rows of which about nine
 * are places — so the count was right and the map was wrong.
 *
 * Two modes, one mechanism:
 *   - plain: every place that the console offers and cannot open, said in one
 *     line each.
 *   - `--compare`: the same map against a snapshot taken earlier, which is what
 *     turns "it looked fine when I checked" into a list. Take the snapshot
 *     BEFORE a move with `--snapshot`.
 *
 * Reads only. It never repairs a map: a place whose record is gone is a
 * question about that site's data, and answering it by deleting the place would
 * hide exactly what someone needs to see.
 */
#[AsCommand(
    name: 'cms:map:check',
    description: 'Report places the console offers and cannot open, and what changed since a snapshot.',
)]
final class CmsMapCheckCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected SiteMapProjector $projector;

    #[InjectAsReadonly]
    protected ContentSurfaceRegistry $surfaces;

    protected function configure(): void
    {
        $this->setName('cms:map:check')
            ->setDescription('Report places the console offers and cannot open, and what changed since a snapshot.')
            ->addOption('snapshot', null, InputOption::VALUE_REQUIRED, 'Write the map to this file, for a later --compare')
            ->addOption('compare', null, InputOption::VALUE_REQUIRED, 'Compare the map against a snapshot taken earlier')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the report as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $integrity = new SiteMapIntegrity();

        $providers = $this->projector->providers();
        if ($providers === []) {
            return $this->nothingToCheck($output, $json);
        }

        $sites = [];
        $problemCount = 0;

        foreach ($providers as $provider) {
            $checks = $integrity->check(
                $provider->places(),
                fn (string $editorId): bool => $this->surfaces->editor($editorId) !== null,
                fn (string $editorId, string $ref): bool => $this->surfaces->editor($editorId)?->load($ref) !== null,
                fn (string $source): bool => $this->surfaces->collection($source) !== null,
            );

            $problems = $integrity->problems($checks);
            $problemCount += count($problems);

            $sites[$provider->siteRef()] = [
                'title' => $provider->siteTitle(),
                'places' => array_map(static fn (PlaceCheck $c): array => $c->toArray(), $checks),
                'problems' => array_map(static fn (PlaceCheck $c): string => $c->explain(), $problems),
            ];
        }

        $snapshot = (string) ($input->getOption('snapshot') ?? '');
        if ($snapshot !== '') {
            $this->writeSnapshot($snapshot, $sites, $output, $json);
        }

        $changes = [];
        $compare = (string) ($input->getOption('compare') ?? '');
        if ($compare !== '') {
            $changes = $this->compareWith($compare, $sites, $output);
            if ($changes === null) {
                return Command::FAILURE;
            }
        }

        return $json
            ? $this->emitJson($output, $sites, $changes, $problemCount)
            : $this->emitText($output, $sites, $changes, $problemCount);
    }

    /**
     * @param array<string, mixed> $sites
     * @return array<string, list<\Semitexa\Cms\Domain\Model\MapChangeRecord>>|null null when the snapshot cannot be read
     */
    private function compareWith(string $path, array $sites, OutputInterface $output): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            $output->writeln(sprintf('<error>Cannot read the snapshot at %s</error>', $path));

            return null;
        }

        try {
            /** @var array<string, mixed> $before */
            $before = (array) json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $output->writeln(sprintf('<error>The snapshot at %s is not readable JSON: %s</error>', $path, $e->getMessage()));

            return null;
        }

        return (new SiteMapDiff())->between($before, $sites);
    }

    /** @param array<string, mixed> $sites */
    private function writeSnapshot(string $path, array $sites, OutputInterface $output, bool $json): void
    {
        $written = @file_put_contents(
            $path,
            (string) json_encode($sites, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        if (!$json) {
            $output->writeln($written === false
                ? sprintf('<error>Could not write the snapshot to %s</error>', $path)
                : sprintf('Snapshot written to <info>%s</info>', $path));
        }
    }

    /**
     * @param array<string, array{title: string, places: list<array<string, mixed>>, problems: list<string>}> $sites
     * @param array<string, list<MapChangeRecord>> $changes
     */
    private function emitText(OutputInterface $output, array $sites, array $changes, int $problemCount): int
    {
        foreach ($sites as $siteRef => $site) {
            $output->writeln(sprintf("\n<info>%s</info> (%s) — %d place(s)", $site['title'], (string) $siteRef, count($site['places'])));

            foreach ($site['problems'] as $problem) {
                $output->writeln('  <comment>·</comment> ' . $problem);
            }

            foreach ($changes[$siteRef] ?? [] as $change) {
                $output->writeln('  <comment>Δ</comment> ' . $change->message);
            }

            if ($site['problems'] === [] && ($changes[$siteRef] ?? []) === []) {
                $output->writeln('  every place opens');
            }
        }

        foreach ($changes as $siteRef => $lines) {
            if (!isset($sites[$siteRef])) {
                foreach ($lines as $change) {
                    $output->writeln(sprintf("\n<error>%s</error>", $change->message));
                }
            }
        }

        // A problem is a finding, not a failure of the command: the exit code
        // says whether the MAP is sound, which is what a release gate or a
        // post-move step wants to branch on.
        return $problemCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array<string, mixed> $sites
     * @param array<string, list<MapChangeRecord>> $changes
     */
    private function emitJson(OutputInterface $output, array $sites, array $changes, int $problemCount): int
    {
        $output->writeln((string) json_encode([
            'artifact' => 'semitexa.cms.map-check/v1',
            'clean' => $problemCount === 0,
            'problems' => $problemCount,
            'sites' => $sites,
            'changes' => array_map(
                static fn (array $records): array => array_map(
                    static fn (MapChangeRecord $record): array => $record->toArray(),
                    $records,
                ),
                $changes,
            ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $problemCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function nothingToCheck(OutputInterface $output, bool $json): int
    {
        $skipped = $this->projector->skippedTenants();

        if ($json) {
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa.cms.map-check/v1',
                'clean' => true,
                'problems' => 0,
                'sites' => [],
                'changes' => [],
                'note' => $skipped === []
                    ? 'no site map declared here'
                    : 'no map for this tenant; maps exist for: ' . implode(', ', $skipped),
            ], JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        // The likeliest mistake by far, and the same one cms:map:build names.
        $output->writeln($skipped === []
            ? '<comment>No site map declared. A module describes its own map with #[AsSiteMap].</comment>'
            : sprintf(
                '<comment>Nothing to check here (tenant: %s). Maps are declared for: %s — run e.g. `tenant:run %s cms:map:check`.</comment>',
                $this->projector->currentTenantId(),
                implode(', ', $skipped),
                $skipped[0],
            ));

        return Command::SUCCESS;
    }
}
