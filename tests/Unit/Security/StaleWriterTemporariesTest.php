<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * #1357: a killed whitelist update or RRD restore leaves its temporary file.
 * The next run removes abandoned ones and keeps a live writer's file, look-alikes
 * and anything that is not a regular file.
 */

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

function stale_temporaries_run(string $scenario, string $directory): array
{
    $root = dirname(__DIR__, 3);
    $program = 'namespace StaleTemporariesProbe;'
        . 'require ' . var_export($root . '/include/vendor/autoload.php', true) . ';'
        . 'function cacti_log($m,$o=false,$e="",$l=0){}'
        . 'function rrd_maintenance_filesystem(){return new \Symfony\Component\Filesystem\Filesystem();}'
        . '$config=["rrd_maintenance_trusted_gids"=>[],"rrd_maintenance_trusted_uids"=>[]];';
    foreach ([['lib/input_whitelist.php', 'data_input_whitelist_update'], ['lib/input_whitelist.php', 'data_input_whitelist_remove_stale'],
        ['lib/rrd_maintenance.php', 'rrd_maintenance_directory_is_trusted'], ['lib/rrd_maintenance.php', 'rrd_maintenance_remove_stale_restores'],
        ['lib/rrd_maintenance.php', 'rrd_maintenance_restore_atomic']] as [$file, $function]) {
        $source = file_get_contents($root . '/' . $file);
        expect($source)->not->toBeFalse();
        $program .= test_php_function_source($source, $function);
    }
    $directoryCode = var_export($directory, true);
    $program .= match ($scenario) {
        'whitelist' => '$r=[["id"=>1,"name"=>"one","hash"=>"h1","input_string"=>"new"]];'
            . 'data_input_whitelist_update(' . $directoryCode . '."/whitelist.json", fn()=>[$r,false], false, hrtime(true)/1e9+30); echo "ok";',
        'restore' => 'echo rrd_maintenance_restore_atomic(' . $directoryCode . '."/dump.xml", ' . $directoryCode . '."/graph.rrd",'
            . ' function($t){return file_put_contents($t,"NEW")===3;}) ? "ok" : "failed";',
        // A bulk caller restores RRDs of one directory in turn; a candidate abandoned
        // after the first sweep waits for a later process instead of a rescan per file.
        'restore-twice' => '$w=function($t){return file_put_contents($t,"NEW")===3;};'
            . '$first=rrd_maintenance_restore_atomic(' . $directoryCode . '."/dump.xml", ' . $directoryCode . '."/graph.rrd", $w);'
            . 'file_put_contents(' . $directoryCode . '."/.rrd-restore-Late99","partial");touch(' . $directoryCode . '."/.rrd-restore-Late99", time()-90000);'
            . '$second=rrd_maintenance_restore_atomic(' . $directoryCode . '."/dump.xml", ' . $directoryCode . '."/other.rrd", $w);'
            . 'echo $first && $second ? "ok" : "failed";',
    };

    return test_php_run($program);
}

function stale_temporaries_directory(array $files): string
{
    $directory = sys_get_temp_dir() . '/kadupul-stale-' . bin2hex(random_bytes(6));
    expect(mkdir($directory, 0700))->toBeTrue();
    foreach ($files as $name => [$kind, $age]) {
        $path = $directory . '/' . $name;
        match ($kind) {
            'file' => expect(file_put_contents($path, 'partial'))->not->toBeFalse(),
            'dir' => expect(mkdir($path))->toBeTrue(),
            'link' => expect(symlink($directory . '/graph.rrd', $path))->toBeTrue(),
        };
        if ($kind !== 'link') {
            expect(touch($path, time() - $age))->toBeTrue();
        }
    }

    return $directory;
}

function stale_temporaries_cleanup(string $directory): void
{
    foreach (scandir($directory) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $path = $directory . '/' . $entry;
            is_dir($path) && !is_link($path) ? rmdir($path) : unlink($path);
        }
    }
    rmdir($directory);
}

test('a whitelist update removes abandoned replacements and keeps everything else', function () {
    $stale = '.whitelist-' . str_repeat('a', 32);
    $recent = '.whitelist-' . str_repeat('b', 32);
    $directory = stale_temporaries_directory([
        'whitelist.json' => ['file', 0],
        $stale => ['file', 7200],
        $recent => ['file', 60],
        '.whitelist-short' => ['file', 7200],
        '.whitelist-' . str_repeat('c', 32) => ['dir', 7200],
    ]);
    file_put_contents($directory . '/whitelist.json', '{"h1":"old"}');
    try {
        $result = stale_temporaries_run('whitelist', $directory);
        $entries = array_values(array_diff(scandir($directory), ['.', '..']));
        $published = file_get_contents($directory . '/whitelist.json');
    } finally {
        stale_temporaries_cleanup($directory);
    }

    expect($result['status'])->toBe(0, $result['err'])->and($result['out'])->toBe('ok')
        ->and($published)->toBe('{"h1":"new"}')
        ->and($entries)->not->toContain($stale)
        ->and($entries)->toContain($recent, '.whitelist-short', '.whitelist-' . str_repeat('c', 32), 'whitelist.json.lock');
});

test('an RRD restore removes candidates abandoned for a day and keeps a running restore', function () {
    $directory = stale_temporaries_directory([
        'graph.rrd' => ['file', 0],
        '.rrd-restore-Ab12Cd' => ['file', 90000],
        '.rrd-restore-Ef34Gh' => ['file', 600],
        '.rrd-restore-Ij56Kl' => ['link', 0],
        '.rrd-restore-' => ['file', 90000],
        'other.rrd' => ['file', 90000],
    ]);
    try {
        $result = stale_temporaries_run('restore', $directory);
        $entries = array_values(array_diff(scandir($directory), ['.', '..']));
        $restored = file_get_contents($directory . '/graph.rrd');
    } finally {
        stale_temporaries_cleanup($directory);
    }

    expect($result['status'])->toBe(0, $result['err'])->and($result['out'])->toBe('ok')
        ->and($restored)->toBe('NEW')
        ->and($entries)->not->toContain('.rrd-restore-Ab12Cd')
        ->and($entries)->toContain('.rrd-restore-Ef34Gh', '.rrd-restore-Ij56Kl', '.rrd-restore-', 'other.rrd')
        ->and(count(preg_grep('/^\.rrd-restore-/', $entries)))->toBe(3);
});

test('the sweeps read only the writer directory when its path holds glob metacharacters', function (string $scenario, string $stale, string $target) {
    $parent = sys_get_temp_dir() . '/kadupul-stale-' . bin2hex(random_bytes(6));
    $directory = $parent . '/s[t]';
    $sibling = $parent . '/st';
    expect(mkdir($directory, 0700, true))->toBeTrue()->and(mkdir($sibling, 0700))->toBeTrue();
    foreach ([$directory, $sibling] as $folder) {
        expect(file_put_contents($folder . '/' . $stale, 'partial'))->not->toBeFalse()
            ->and(touch($folder . '/' . $stale, time() - 90000))->toBeTrue();
    }
    expect(file_put_contents($directory . '/' . $target, '{"h1":"old"}'))->not->toBeFalse();
    try {
        $result = stale_temporaries_run($scenario, $directory);
        $own = array_values(array_diff(scandir($directory), ['.', '..']));
        $other = array_values(array_diff(scandir($sibling), ['.', '..']));
    } finally {
        stale_temporaries_cleanup($directory);
        stale_temporaries_cleanup($sibling);
        rmdir($parent);
    }

    expect($result['status'])->toBe(0, $result['err'])->and($result['out'])->toBe('ok')
        ->and($own)->not->toContain($stale)
        ->and($other)->toBe([$stale]);
})->with([
    'whitelist' => ['whitelist', '.whitelist-' . str_repeat('d', 32), 'whitelist.json'],
    'restore' => ['restore', '.rrd-restore-Mn78Op', 'graph.rrd'],
]);

test('a process reads each RRD directory for abandoned candidates at most once an hour', function () {
    $directory = stale_temporaries_directory([
        'graph.rrd' => ['file', 0],
        'other.rrd' => ['file', 0],
        '.rrd-restore-Ab12Cd' => ['file', 90000],
    ]);
    try {
        $result = stale_temporaries_run('restore-twice', $directory);
        $entries = array_values(array_diff(scandir($directory), ['.', '..']));
    } finally {
        stale_temporaries_cleanup($directory);
    }

    expect($result['status'])->toBe(0, $result['err'])->and($result['out'])->toBe('ok')
        ->and($entries)->not->toContain('.rrd-restore-Ab12Cd')
        ->and($entries)->toContain('.rrd-restore-Late99');
});
