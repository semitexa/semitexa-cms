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
        $compare = (string) ($input->getOption('compare') ?? '');

        // No providers is "nothing to check" only when nobody asked what
        // CHANGED. With --compare it is the loudest answer the command has: a
        // map that used to exist and does not any more is SiteGone, and
        // returning early here meant the single-site case — the common one —
        // could never report it. It said "nothing to check" and exited 0.
        if ($providers === [] && $compare === '') {
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

        // READ BEFORE WRITE. `--snapshot=x --compare=x` is a reasonable thing
        // to type — take the new picture where the old one lives — and writing
        // first replaced the baseline before anything had read it: the
        // comparison then reported no changes, and the record of what the map
        // used to be was gone.
        $changes = [];
        if ($compare !== '') {
            $changes = $this->compareWith($compare, $sites, $output, $json);
            if ($changes === null) {
                return Command::FAILURE;
            }
        }

        $snapshot = (string) ($input->getOption('snapshot') ?? '');
        if ($snapshot !== '' && !$this->writeSnapshot($snapshot, $sites, $output, $json)) {
            return Command::FAILURE;
        }

        return $json
            ? $this->emitJson($output, $sites, $changes, $problemCount)
            : $this->emitText($output, $sites, $changes, $problemCount);
    }

    /**
     * @param array<string, mixed> $sites
     * @return array<string, list<\Semitexa\Cms\Domain\Model\MapChangeRecord>>|null null when the snapshot cannot be read
     */
    private function compareWith(string $path, array $sites, OutputInterface $output, bool $json = false): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            $this->fail($output, $json, sprintf('Cannot read the snapshot at %s', $path));

            return null;
        }

        try {
            /** @var array<string, mixed> $before */
            $before = (array) json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->fail($output, $json, sprintf('The snapshot at %s is not readable JSON: %s', $path, $e->getMessage()));

            return null;
        }

        return (new SiteMapDiff())->between($before, $sites);
    }

    /**
     * False when the snapshot did not reach the disk.
     *
     * The caller turns that into a failing exit code, and the message is
     * printed in JSON mode too. The whole point of `--snapshot` is that a
     * later `--compare` has something to read: a migration script that takes
     * the snapshot, sees a 0, moves the pages and then finds no file has lost
     * the only record of what the map looked like before.
     *
     * @param array<string, mixed> $sites
     */
    private function writeSnapshot(string $path, array $sites, OutputInterface $output, bool $json): bool
    {
        // SUBSTITUTE rather than a silent ''. json_encode() returns false on
        // a byte it cannot represent, and the string cast turned that into an
        // empty file reported as a successful snapshot.
        $encoded = json_encode(
            $sites,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        $written = $encoded === false ? false : @file_put_contents($path, $encoded);

        if ($written === false) {
            $this->fail($output, $json, sprintf('Could not write the snapshot to %s', $path));

            return false;
        }

        if (!$json) {
            $output->writeln(sprintf('Snapshot written to <info>%s</info>', $path));
        }

        return true;
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
        return self::verdict($problemCount, $changes);
    }

    /**
     * @param array<string, mixed> $sites
     * @param array<string, list<MapChangeRecord>> $changes
     */
    private function emitJson(OutputInterface $output, array $sites, array $changes, int $problemCount): int
    {
        $output->writeln((string) json_encode([
            'artifact' => 'semitexa.cms.map-check/v1',
            'clean' => self::verdict($problemCount, $changes) === Command::SUCCESS,
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

        return self::verdict($problemCount, $changes);
    }

    /**
     * Say what went wrong in the shape the caller is reading.
     *
     * EVERY failure path, not just the snapshot write. In `--json` mode the
     * caller's only channel is machine-readable, and a bare sentence there is
     * a parse error at the other end — an unreadable snapshot used to end the
     * run with `<error>` text and no artifact at all.
     */
    private function fail(OutputInterface $output, bool $json, string $message): void
    {
        $output->writeln($json
            ? (string) json_encode([
                'artifact' => 'semitexa.cms.map-check/v1',
                'clean' => false,
                'error' => $message,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : sprintf('<error>%s</error>', $message));
    }

    /**
     * Sound means BOTH halves: no place the console cannot open, and nothing
     * the comparison found.
     *
     * A change was not counted before, so `--compare` could report a whole
     * site gone and still exit 0 — the fix that taught it to reach the
     * comparison at all would have been wasted, because the script branching
     * on the exit code would have carried on regardless.
     *
     * @param array<string, list<MapChangeRecord>> $changes
     */
    private static function verdict(int $problemCount, array $changes): int
    {
        $changed = false;
        foreach ($changes as $records) {
            if ($records !== []) {
                $changed = true;
                break;
            }
        }

        return $problemCount === 0 && !$changed ? Command::SUCCESS : Command::FAILURE;
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
