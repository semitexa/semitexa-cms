<?php

declare(strict_types=1);

namespace Semitexa\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cms\Application\Console\Command\CmsMapCheckCommand;

/**
 * The baseline survives a write that does not finish.
 *
 * The snapshot is what the NEXT run compares against, so a truncated one is
 * worse than none: it reports differences that are artefacts of the write, on
 * a check whose whole job is to say whether the map changed. Writing straight
 * to the path truncates the old baseline before the new bytes land, and a
 * short count came back as success.
 */
final class MapSnapshotIsWrittenWholeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cms-map-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->dir, 0o777);
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            if (is_file($entry)) {
                @unlink($entry);
            }
        }
        @rmdir($this->dir);
    }

    #[Test]
    public function aCompletedWriteReplacesTheBaselineAndLeavesNoTemporary(): void
    {
        $path = $this->dir . '/map.json';
        file_put_contents($path, 'the previous baseline');

        self::assertTrue($this->write($path, '{"sites":[]}'));
        self::assertSame('{"sites":[]}', file_get_contents($path));
        self::assertSame(
            [$path],
            array_values(array_filter(glob($this->dir . '/*') ?: [], 'is_file')),
            'the temporary file is renamed, not left beside the baseline'
        );
    }

    #[Test]
    public function aWriteThatCannotStartLeavesTheOldBaselineWhereItWas(): void
    {
        $path = $this->dir . '/map.json';
        file_put_contents($path, 'the previous baseline');
        chmod($this->dir, 0o500);

        if (is_writable($this->dir)) {
            self::markTestSkipped('Running as a user that writes read-only directories; the failure cannot be staged.');
        }

        self::assertFalse($this->write($path, '{"sites":[]}'));
        self::assertSame(
            'the previous baseline',
            file_get_contents($path),
            'the answer the next run compares against is still the one it was given'
        );
    }

    private function write(string $path, string $contents): bool
    {
        $method = new \ReflectionMethod(CmsMapCheckCommand::class, 'writeAtomically');

        return (bool) $method->invoke(new CmsMapCheckCommand(), $path, $contents);
    }
}
