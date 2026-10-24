<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Graphing;

use PHPUnit\Framework\TestCase;

/**
 * A scratch directory under sys_get_temp_dir() for real RRD files. Every
 * path a test creates goes through here, and remove() deletes symlinks
 * without following them, so cleanup cannot reach outside the directory.
 */
final class TemporaryRrdRoot
{
    private const CANDIDATES = ['/opt/homebrew/bin/rrdtool', '/usr/local/bin/rrdtool', '/usr/bin/rrdtool'];

    public readonly string $path;

    private function __construct(string $path)
    {
        $this->path = $path;
    }

    public static function create(): self
    {
        $base = realpath(sys_get_temp_dir());
        if ($base === false) {
            throw new \RuntimeException('The system temporary directory does not exist.');
        }
        $path = $base . '/kadupul-rrd-' . bin2hex(random_bytes(8));
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException('Could not create a temporary RRD root.');
        }

        return new self($path);
    }

    /** The rrdtool binary, or a skipped test that says why; never a silent pass. */
    public static function rrdtoolOrSkip(TestCase $test): string
    {
        $configured = getenv('KADUPUL_TEST_RRDTOOL');
        foreach ($configured !== false && $configured !== '' ? [$configured] : self::CANDIDATES as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        $test->markTestSkipped('rrdtool is not installed (looked in ' . implode(', ', self::CANDIDATES) . ', or set KADUPUL_TEST_RRDTOOL); real RRD file behaviour was not exercised.');
    }

    /**
     * @param list<string> $dataSources such as DS:traffic_in:COUNTER:600:0:U
     */
    public function createRrd(string $rrdtool, string $relative, array $dataSources, int $start = GraphingSamples::EPOCH): string
    {
        $file = $this->inside($relative);
        $this->ensureDirectory(dirname($file));
        $this->run($rrdtool, ['create', $file, '--start', (string) $start, '--step', '300', ...$dataSources, 'RRA:AVERAGE:0.5:1:100', 'RRA:LAST:0.5:1:100']);

        return $file;
    }

    public function write(string $relative, string $contents): string
    {
        $file = $this->inside($relative);
        $this->ensureDirectory(dirname($file));
        file_put_contents($file, $contents);

        return $file;
    }

    public function symlink(string $target, string $relative): string
    {
        $link = $this->inside($relative);
        $this->ensureDirectory(dirname($link));
        if (!symlink($target, $link)) {
            throw new \RuntimeException('Could not create a test symlink.');
        }

        return $link;
    }

    public function directory(string $relative): string
    {
        $directory = $this->inside($relative);
        $this->ensureDirectory($directory);

        return $directory;
    }

    /**
     * rrdtool lastupdate prints the raw text last written to each data
     * source, before any rate is computed, so it shows exactly what reached
     * the file.
     *
     * @return array{int, array<string, string>} the newest row's second and its values by data-source name
     */
    public function lastUpdate(string $rrdtool, string $file): array
    {
        $lines = array_values(array_filter(explode("\n", $this->run($rrdtool, ['lastupdate', $file])), static fn(string $l): bool => trim($l) !== ''));
        $names = preg_split('/\s+/', trim($lines[0])) ?: [];
        [$second, $values] = explode(':', $lines[count($lines) - 1], 2) + [1 => ''];

        return [(int) $second, array_combine($names, preg_split('/\s+/', trim($values)) ?: [])];
    }

    /** @return list<array{int, list<string>}> each row of the AVERAGE archive between the two seconds */
    public function fetch(string $rrdtool, string $file, int $start, int $end): array
    {
        $rows = [];
        foreach (explode("\n", $this->run($rrdtool, ['fetch', $file, 'AVERAGE', '--start', (string) $start, '--end', (string) $end])) as $line) {
            if (preg_match('/\A([0-9]+): (.*)\z/', trim($line), $m) === 1) {
                // rrdtool prints unknown as printf("%e") of its stored NaN. x86
                // builds store a sign-bit NaN that glibc prints as -nan; the sign
                // of a NaN carries no value, so both spellings mean unknown.
                $values = preg_split('/\s+/', trim($m[2])) ?: [];
                $rows[] = [(int) $m[1], array_map(static fn(string $v): string => $v === '-nan' ? 'nan' : $v, $values)];
            }
        }

        return $rows;
    }

    /** @param list<string> $arguments */
    public function run(string $rrdtool, array $arguments): string
    {
        $process = proc_open([$rrdtool, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start rrdtool.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('rrdtool ' . $arguments[0] . ' failed: ' . $stderr);
        }

        return $stdout;
    }

    public function remove(): void
    {
        if (!is_dir($this->path) || is_link($this->path)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $name = $entry->getPathname();
            if (!str_starts_with($name, $this->path . '/')) {
                throw new \LogicException('Refusing to delete outside the temporary RRD root.');
            }
            if ($entry->isLink() || !$entry->isDir()) {
                unlink($name);
            } else {
                rmdir($name);
            }
        }
        rmdir($this->path);
    }

    private function inside(string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            throw new \LogicException('Test files stay inside the temporary RRD root.');
        }

        return $this->path . '/' . $relative;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Could not create a test directory.');
        }
    }
}
