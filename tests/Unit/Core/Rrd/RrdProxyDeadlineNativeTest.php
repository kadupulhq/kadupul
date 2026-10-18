<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';
require_once dirname(__DIR__, 3) . '/Helpers/RrdProxyDeadlineCoverageRegistration.php';

test('proxy reply deadlines bound silence and partial frames and preserve admitted reuse', function (string $scenario, string $outputFlag) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/rrd-proxy-deadline-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $command = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root,
        '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/rrd-proxy-command-deadline.php', $scenario, $directory, $outputFlag];
    if ($coverage !== null) {
        $command[] = 'coverage';
    }
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    try {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $limit = microtime(true) + 6;
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $state = proc_get_status($process);
            if (!$state['running']) {
                break;
            }
            if (microtime(true) >= $limit) {
                proc_terminate($process, 9);
                $this->fail('Native proxy fixture exceeded its six-second bound');
            }
            usleep(10000);
        } while (true);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        expect($state['exitcode'])->toBe(0, $stderr . $stdout);
        expect($stderr)->toBe('');
        [$result, $second, $elapsed, $commands, $logs, $closed] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        expect($closed)->toBe($scenario !== 'success');
        if ($scenario === 'success') {
            expect($result)->toBe('payload-0')->and($second)->toBe('payload-1');
            expect($commands)->toBe("info ./sample.rrd_EOT_\r\ninfo ./second.rrd_EOT_\r\n");
        } else {
            expect($result)->toBeNull()->and($second)->toBeNull();
            expect($elapsed)->toBeLessThan($scenario === 'configured' ? 1.4 : 0.7);
            if ($scenario === 'configured') {
                expect($elapsed)->toBeGreaterThan(0.8);
            }
            expect($commands)->toBe($scenario === 'expired' ? '' : "info ./sample.rrd_EOT_\r\n");
            expect(implode("\n", $logs))->toContain('Time-out while reading');
        }
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $arguments = [$reports[0], $root, RrdProxyDeadlineCoverageRegistration::PRODUCER,
                json_encode([$scenario, $outputFlag], JSON_THROW_ON_ERROR),
                RrdProxyDeadlineCoverageRegistration::SOURCES, RrdProxyDeadlineCoverageRegistration::MARKERS,
                RrdProxyDeadlineCoverageRegistration::HITS];
            $measured = NativeChildCoverageEvidence::load(...$arguments);
            expect(NativeChildCoverageEvidence::verifyRejections(...[...$arguments, 'lib/rrd.php']))
                ->toBe(count(RrdProxyDeadlineCoverageRegistration::SOURCES) + count(RrdProxyDeadlineCoverageRegistration::MARKERS) + 10);
            $coverage->merge($measured);
        }
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with([
    ['configured', 'RRDTOOL_OUTPUT_STDOUT'],
    ['silent', 'RRDTOOL_OUTPUT_STDOUT'],
    ['drip', 'RRDTOOL_OUTPUT_STDOUT'],
    ['expired', 'RRDTOOL_OUTPUT_STDOUT'],
    ['success', 'RRDTOOL_OUTPUT_STDOUT'],
    ['silent', 'RRDTOOL_OUTPUT_NULL'],
    ['silent', 'RRDTOOL_OUTPUT_STDERR'],
    ['silent', 'RRDTOOL_OUTPUT_RETURN_STDERR'],
    ['silent', 'RRDTOOL_OUTPUT_GRAPH_DATA'],
    ['silent', 'RRDTOOL_OUTPUT_BOOLEAN'],
]);
