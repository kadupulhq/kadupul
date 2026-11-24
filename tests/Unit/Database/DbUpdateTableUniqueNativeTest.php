<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__, 2) . '/Helpers/PestCodeCoverageCompatibility.php';

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

// lib/functions.php declares functions that other unit tests stub, so each
// rebuild runs in tests/Fixtures/db-update-table-unique-native.php, a process
// of its own, and reports what it observed.
final class DbUpdateTableUniqueNativeTest extends TestCase
{
    use PestCodeCoverageCompatibility;

    #[DataProvider('rebuilds')]
    public function testRebuildPreservesUniqueness(string $before, array $after, bool $success): void
    {
        if (!getenv('KADUPUL_TEST_MYSQL_DSN')) {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a disposable database.');
        }
        $root = dirname(__DIR__, 3);
        $directory = sys_get_temp_dir() . '/unique-rebuild-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $coverage = $this->getTestResultObject()->getCodeCoverage();
        try {
            $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $root . '/tests/Fixtures/db-update-table-unique-native.php', $before, json_encode($after, JSON_THROW_ON_ERROR)];
            if ($coverage !== null) {
                $command[] = $directory;
            }
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors . $output);
            $seen = json_decode((string) $output, true, 8, JSON_THROW_ON_ERROR);

            self::assertTrue($seen['created']);
            self::assertTrue($seen['seeded']);
            self::assertSame($success, $seen['updated']);
            self::assertSame([0], $seen['non_unique']);
            self::assertSame($success ? $after : ['a', 'b'], $seen['columns']);
            self::assertFalse($seen['duplicate']);
            self::assertSame('23000', $seen['error']);
            self::assertSame(2, $seen['rows']);
            self::assertTrue($seen['dropped']);
            if ($coverage !== null) {
                $reports = glob($directory . '/*.coverage');
                self::assertCount(1, $reports);
                $coverage->merge(unserialize((string) file_get_contents($reports[0])));
            }
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function rebuilds(): array
    {
        return [
            'column reorder' => ['a,b', ['b', 'a'], true],
            'column removal preserves uniqueness' => ['a,b', ['b'], true],
            'duplicate rebuild preserves original index' => ['a,b', ['a'], false],
        ];
    }
}
