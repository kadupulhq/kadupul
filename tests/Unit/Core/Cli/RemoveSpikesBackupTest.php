<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

function spikeBackupCommand($args, $env = null) {
    $process = proc_open($args, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return array(proc_close($process), $stdout, $stderr);
}

beforeEach(function () {
    $this->binary = getenv('RRDTOOL_TEST_BINARY') ?: (is_executable('/usr/bin/rrdtool') ? '/usr/bin/rrdtool' : '/opt/homebrew/bin/rrdtool');
    if (!is_executable($this->binary)) { $this->markTestSkipped('RRDtool is required for CLI backup contracts'); }
    $this->root = dirname(__DIR__, 4);
    $this->dir = sys_get_temp_dir() . '/spike-cli-' . bin2hex(random_bytes(6));
    foreach (array('', '/cli', '/include', '/backup') as $suffix) { mkdir($this->dir . $suffix, 0700); }
    copy($this->root . '/cli/removespikes.php', $this->dir . '/cli/removespikes.php');
    copy($this->root . '/tests/fixtures/spikekill-cli-bootstrap.php', $this->dir . '/include/cli_check.php');
    $this->rrd = $this->dir . '/source.rrd';
    expect(spikeBackupCommand(array($this->binary, 'create', $this->rrd, '--start', '1700000000', '--step', '60', 'DS:value:GAUGE:120:0:U', 'RRA:AVERAGE:0.5:1:100'))[0])->toBe(0);
    $samples = array();
    for ($i = 1; $i <= 60; $i++) { $samples[] = (1700000000 + $i * 60) . ':' . ($i === 30 ? 100000 : 10); }
    expect(spikeBackupCommand(array_merge(array($this->binary, 'update', $this->rrd), $samples))[0])->toBe(0);
    $this->before = file_get_contents($this->rrd);
    $this->env = array_merge(getenv(), array('SPIKE_TEST_ROOT' => $this->root, 'SPIKE_TEST_RRDTOOL' => $this->binary, 'SPIKE_TEST_BACKUP' => $this->dir . '/backup'));
    $this->args = array(PHP_BINARY, $this->dir . '/cli/removespikes.php', '--rrdfile=' . $this->rrd, '--method=stddev', '--avgnan=avg', '--stddev=1', '--outliers=2', '--number=100');
});

afterEach(function () {
    if (!isset($this->dir)) { return; }
    chmod($this->dir . '/backup', 0700);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($this->dir);
});

test('CLI backup snapshots preserve the original real RRD and dry runs never modify it', function ($backup, $dryrun) {
    $args = $this->args;
    if ($backup) { $args[] = '--backup'; }
    if ($dryrun) { $args[] = '--dryrun'; }
    [$status, $out, $err] = spikeBackupCommand($args, $this->env);
    $this->assertSame(0, $status, $out . $err);
    $snapshots = glob($this->dir . '/backup/source.backup.*.rrd');
    expect($snapshots)->toHaveCount($backup && !$dryrun ? 1 : 0);
    if ($backup && !$dryrun) { expect(file_get_contents($snapshots[0]))->toBe($this->before); }
    if ($dryrun) {
        expect(file_get_contents($this->rrd))->toBe($this->before)
            ->and(glob($this->dir . '/backup/*'))->toBe(array());
    } else {
        expect(file_get_contents($this->rrd))->not->toBe($this->before)
            ->and(spikeBackupCommand(array($this->binary, 'info', $this->rrd))[0])->toBe(0);
    }
})->with(array(array(false, false), array(true, false), array(false, true), array(true, true)));

test('CLI stops before modifying the RRD when the requested backup cannot be written', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) { $this->markTestSkipped('Root bypasses directory write permissions'); }
    $wrapper = $this->dir . '/rrdtool-wrapper';
    file_put_contents($wrapper, "#!/bin/sh\nif [ \"$1\" = dump ]; then chmod 500 " . escapeshellarg($this->dir . '/backup') . "; fi\nexec " . escapeshellarg($this->binary) . " \"$@\"\n");
    chmod($wrapper, 0700);
    $this->env['SPIKE_TEST_RRDTOOL'] = $wrapper;
    [$status, $out, $err] = spikeBackupCommand(array_merge($this->args, array('--backup')), $this->env);
    expect($status)->not->toBe(0)
        ->and($out)->toContain('Backup')
        ->and(file_get_contents($this->rrd))->toBe($this->before)
        ->and(glob($this->dir . '/backup/*.rrd'))->toBe(array());
});


test('requested backups survive a failed restore and retain the original bytes', function () {
    $wrapper = $this->dir . '/rrdtool-wrapper';
    file_put_contents($wrapper, "#!/bin/sh\nif [ \"$1\" = restore ]; then echo restore-failed >&2; exit 1; fi\nexec " . escapeshellarg($this->binary) . " \"$@\"\n");
    chmod($wrapper, 0700);
    $this->env['SPIKE_TEST_RRDTOOL'] = $wrapper;
    [$status, $out] = spikeBackupCommand(array_merge($this->args, array('--backup')), $this->env);
    $snapshots = glob($this->dir . '/backup/source.backup.*.rrd');
    expect($status)->not->toBe(0)
        ->and($out)->toContain('Unable to restore')
        ->and($snapshots)->toHaveCount(1)
        ->and(file_get_contents($snapshots[0]))->toBe($this->before)
        ->and(file_get_contents($this->rrd))->toBe($this->before)
        ->and(glob($this->dir . '/backup/*.xml'))->toBe(array());
});

test('repeated requested backups preserve every snapshot including a no-spike run', function () {
    [$status, $out, $err] = spikeBackupCommand(array_merge($this->args, array('--backup')), $this->env);
    $this->assertSame(0, $status, $out . $err);
    $first = glob($this->dir . '/backup/source.backup.*.rrd')[0];
    $after = file_get_contents($this->rrd);
    // Exclude every sample from spike removal while retaining the backup request.
    $args = array_merge($this->args, array('--backup', '--stddev=1000000'));
    [$status, $out, $err] = spikeBackupCommand($args, $this->env);
    $this->assertSame(0, $status, $out . $err);
    $snapshots = glob($this->dir . '/backup/source.backup.*.rrd');
    expect($snapshots)->toHaveCount(2)
        ->and(file_get_contents($first))->toBe($this->before)
        ->and(file_get_contents(array_values(array_diff($snapshots, array($first)))[0]))->toBe($after)
        ->and(file_get_contents($this->rrd))->toBe($after);
});


test('dry-run statistics handle empty sparse and large-value RRAs without inventing zero values', function ($count, $scale, $html) {
    unlink($this->rrd);
    expect(spikeBackupCommand(array($this->binary, 'create', $this->rrd, '--start', '1700000000', '--step', '60', 'DS:value:GAUGE:120:0:U', 'RRA:AVERAGE:0.5:1:100'))[0])->toBe(0);
    if ($count) {
        $samples = array();
        for ($i = 1; $i <= $count; $i++) { $samples[] = (1700000000 + $i * 60) . ':' . ($scale * $i); }
        expect(spikeBackupCommand(array_merge(array($this->binary, 'update', $this->rrd), $samples))[0])->toBe(0);
    }
    $before = file_get_contents($this->rrd);
    $args = array_merge($this->args, array('--backup', '--dryrun'));
    if ($html) { $args[] = '--html'; }
    [$status, $out, $err] = spikeBackupCommand($args, $this->env);
    $this->assertSame(0, $status, $out . $err);
    expect(file_get_contents($this->rrd))->toBe($before)
        ->and(glob($this->dir . '/backup/*'))->toBe(array());
    if ($count < 3) { expect($out)->toContain('N/A'); }
    else { expect($out)->toMatch('/[0-9]\.[0-9]{2}e\+[0-9]+/i'); }
})->with(array(array(0, 10, false), array(0, 10, true), array(2, 10, false), array(2, 10, true), array(60, 100000000, false), array(60, 100000000, true)));
