<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Crash every file writer that replaces a file by rename at each filesystem
 * call, before and after it, and check what a reader or the next run finds:
 * the old file or the whole new one, never a partial file or none, and a rerun
 * that completes. See tests/Helpers/CrashPoints.php for what is and is not modelled.
 */

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 2) . '/Helpers/CrashPoints.php';

use function Kadupul\Tests\CrashPoints\exhaust;

const CRASH_WRITERS = [
    'whitelist' => [
        'files' => ['whitelist.json' => '{"h1":"old-one"}'],
        'target' => 'whitelist.json',
        'new' => '{"h1":"new-one","h2":"new-two"}',
        // The lock sidecar is kept on purpose; replacements carry a random name.
        'allowed' => '/^(whitelist\.json|whitelist\.json\.lock|\.whitelist-[0-9a-f]{32})$/',
    ],
    'rrd-restore' => [
        'files' => ['graph.rrd' => 'OLD-RRD'],
        'target' => 'graph.rrd',
        'new' => null, // str_repeat('NEW-RRD-', 512), set below
        'allowed' => '/^(graph\.rrd|\.rrd-restore-[A-Za-z0-9]+)$/',
    ],
    'boost-cache' => [
        'files' => ['cache/graph.png' => 'OLDPNG'],
        'target' => 'cache/graph.png',
        'new' => null, // str_repeat("\x89PNG-NEW", 4096), set below
        'allowed' => '/^(cache|cache\/graph\.png|cache\/boost_png_tmp_[0-9a-f]{16})$/',
    ],
];

function crash_expected(string $scenario): string
{
    return match ($scenario) {
        'rrd-restore' => str_repeat('NEW-RRD-', 512),
        'boost-cache' => str_repeat("\x89PNG-NEW", 4096),
        default => CRASH_WRITERS[$scenario]['new'],
    };
}

function crash_prepare(string $scenario): string
{
    $work = sys_get_temp_dir() . '/kadupul-crash-' . bin2hex(random_bytes(6));
    expect(mkdir($work, 0700))->toBeTrue();
    foreach (CRASH_WRITERS[$scenario]['files'] as $name => $bytes) {
        if (!is_dir(dirname($work . '/' . $name))) {
            expect(mkdir(dirname($work . '/' . $name), 0700))->toBeTrue();
        }
        expect(file_put_contents($work . '/' . $name, $bytes))->toBe(strlen($bytes));
    }

    return $work;
}

function crash_remove(string $work): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($work);
}

/** @return list<string> paths relative to the work directory */
function crash_entries(string $work): array
{
    $entries = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $entries[] = substr($entry->getPathname(), strlen($work) + 1);
    }
    sort($entries);

    return $entries;
}

/** @return array{0: int, 1: string, 2: string} */
function crash_run(string $scenario, string $work, int $point): array
{
    $script = dirname(__DIR__, 2) . '/Fixtures/crash-consistency/writer.php';
    $previous = getenv('KADUPUL_CRASH_AT');
    putenv('KADUPUL_CRASH_AT=' . $point);
    try {
        $result = test_php_run([PHP_BINARY, $script, $scenario, $work]);
    } finally {
        putenv($previous === false ? 'KADUPUL_CRASH_AT' : 'KADUPUL_CRASH_AT=' . $previous);
    }

    return [$result['status'], $result['out'], $result['err']];
}

test('a crash at any filesystem call leaves the old file or the whole new one, and a rerun completes', function (string $scenario) {
    $writer = CRASH_WRITERS[$scenario];
    $old = $writer['files'][$writer['target']];
    $new = crash_expected($scenario);
    $states = ['old' => 0, 'new' => 0];
    $leftovers = 0;

    $work = null;
    $points = exhaust(
        function (int $point) use ($scenario, &$work): array {
            if ($work !== null) {
                crash_remove($work);
            }
            $work = crash_prepare($scenario);

            return crash_run($scenario, $work, $point);
        },
        function (int $point, string $err) use ($scenario, $writer, $old, $new, &$work, &$states, &$leftovers): void {
            $context = "{$scenario} {$err}";
            clearstatcache();
            $target = $work . '/' . $writer['target'];
            expect(is_file($target) && !is_link($target))->toBeTrue($context . ': target missing');
            $bytes = file_get_contents($target);
            expect($bytes === $old || $bytes === $new)->toBeTrue($context . ': partial target');
            $states[$bytes === $old ? 'old' : 'new']++;
            foreach (crash_entries($work) as $entry) {
                expect(preg_match($writer['allowed'], $entry))->toBe(1, $context . ': unexpected ' . $entry);
            }

            // Recovery: the next run replaces whatever the crash left and publishes the new file.
            [$status, $out, $rerunErr] = crash_run($scenario, $work, 0);
            expect($status)->toBe(0, $context . ': rerun failed: ' . $out . $rerunErr)
                ->and(file_get_contents($target))->toBe($new, $context . ': rerun did not publish');
            $leftovers += count(array_filter(crash_entries($work), static fn(string $entry): bool => $entry !== $writer['target']
                && !in_array($entry, ['cache', 'whitelist.json.lock'], true)));
        }
    );

    expect(file_get_contents($work . '/' . $writer['target']))->toBe($new);
    crash_remove($work);

    // Both states are reachable, so the crash points straddle the publishing rename.
    expect($points)->toBeGreaterThan(4)->and($states['old'])->toBeGreaterThan(0)->and($states['new'])->toBeGreaterThan(0);
    // A crashed run's temporary file has a random name and is left for an operator; record how often that happens.
    fwrite(STDERR, sprintf("%s: %d crash points, %d old, %d new, %d leftover temporaries after rerun\n", $scenario, $points, $states['old'], $states['new'], $leftovers));
})->with(array_keys(CRASH_WRITERS));

test('the harness reports a scenario that fails outside a crash point', function () {
    $work = crash_prepare('whitelist');
    try {
        expect(fn() => exhaust(
            fn(int $point): array => crash_run('no-such-writer', $work, $point),
            fn(int $point, string $err) => null
        ))->toThrow(RuntimeException::class, 'outside a crash point');
    } finally {
        crash_remove($work);
    }
});
