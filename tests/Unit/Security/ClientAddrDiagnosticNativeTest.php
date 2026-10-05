<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/../../Helpers/NativeChildCoverageEvidence.php';

test('proxy configuration diagnostics preserve native caller outcomes and never repeat or disclose request values', function (array $scenario, string|false $client, bool $diagnostic) {
    $root = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/proxy-diagnostic-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $encoded = json_encode($scenario, JSON_THROW_ON_ERROR);
    $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=24575', '-d', 'pcov.directory=' . $root,
        '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/client-addr-diagnostic-native.php', $encoded, $directory];
    if ($coverage !== null) {
        $command[] = 'coverage';
    }
    try {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($stderr)->toBe('');
        $state = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $authorized = $client === '203.0.113.5';
        expect($state['client'])->toBe($client)->and($state['repeat'])->toBe($client)
            ->and($state['authorized'])->toBe($authorized)->and($state['repeat_authorized'])->toBe($authorized)
            ->and($state['poller'])->toBe($authorized ? 2 : 0)->and($state['writes'])->toBe(1)
            ->and($state['session'])->toBe(['remote_addr' => $client === false ? '' : $client, 'user_id' => 42, 'data' => 'fixture-session']);
        $authLines = array_values(array_filter(explode("\n", $state['logs']), static fn(string $line): bool => str_contains($line, 'AUTH ')));
        expect($authLines)->toHaveCount($diagnostic ? 1 : 0);
        if ($diagnostic) {
            expect($authLines[0])->toContain('proxy_trusted_addresses', 'proxy_headers', 'Legacy boolean')
                ->not->toContain('192.0.2.10', '198.51.100.7', '203.0.113.5', 'request-secret', 'HTTP_X_FORWARDED_FOR');
        }
        expect($state['logs'])->not->toContain('request-secret');
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $sources = ['composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php', 'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Helpers/PhpSource.php', 'tests/Unit/Security/ClientAddrDiagnosticNativeTest.php', 'include/global_constants.php', 'include/session.php', 'remote_agent.php', 'lib/remote_agent_auth.php', 'lib/functions.php', 'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php'];
            $arguments = [$reports[0], $root, 'tests/Fixtures/client-addr-diagnostic-native.php', $encoded, $sources,
                ['client-address-and-caller-outcomes-readback', 'actual-auth-log-readback', 'persisted-session-address-readback'], ['lib/functions.php']];
            $measured = NativeChildCoverageEvidence::load(...$arguments);
            static $verifiedOmissions = false;
            if (!$verifiedOmissions) {
                expect(NativeChildCoverageEvidence::verifyRejections(...array_merge($arguments, ['lib/rrd.php'])))->toBe(count($sources) + 13);
                $verifiedOmissions = true;
            }
            $coverage->merge($measured);
        }
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with(function (): array {
    $base = ['headers' => ['HTTP_X_FORWARDED_FOR'], 'trusted' => ['192.0.2.10'], 'debug' => true,
        'server' => ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5']];
    $case = static fn(array $changes): array => array_replace($base, $changes);
    return [
        'forwarding disabled' => [$case(['headers' => null, 'trusted' => []]), '192.0.2.10', false],
        'valid trusted client' => [$base, '203.0.113.5', false],
        'legacy boolean' => [$case(['headers' => true, 'trusted' => []]), '192.0.2.10', true],
        'legacy header without trusted addresses' => [$case(['trusted' => []]), '192.0.2.10', true],
        'malformed trusted address setting' => [$case(['trusted' => '192.0.2.10']), '192.0.2.10', true],
        'untrusted peer' => [$case(['server' => ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5']]), '198.51.100.7', true],
        'missing trusted header' => [$case(['server' => ['REMOTE_ADDR' => '192.0.2.10']]), false, true],
        'chained trusted header' => [$case(['server' => ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5, request-secret']]), false, true],
        'invalid trusted header' => [$case(['server' => ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => 'request-secret']]), false, true],
        'empty trusted header' => [$case(['server' => ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '']]), false, true],
        'multiple selected headers' => [$case(['headers' => ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP']]), false, true],
        'nested selected header' => [$case(['headers' => [[]]]), false, true],
        'null allowlist' => [$case(['allowed' => null]), false, true],
        'nested allowlist' => [$case(['allowed' => [[]]]), false, true],
        'normal verbosity suppresses diagnostics' => [$case(['headers' => true, 'trusted' => [], 'debug' => false]), '192.0.2.10', false],
        'invalid peer remains denied' => [$case(['server' => ['REMOTE_ADDR' => 'request-secret']]), false, false],
    ];
});
