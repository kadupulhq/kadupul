<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// get_client_addr() runs in a child process because other unit tests declare
// their own get_client_addr() stub, and lib/functions.php would redeclare it.
function runClientAddrProbe($coverage, array $cases): array
{
    $root      = dirname(__DIR__, 3);
    $directory = sys_get_temp_dir() . '/client-addr-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    $program = <<<'PHP'
<?php
$root = $argv[1];
if ($argv[2] === 'coverage') {
    define('CLIENT_ADDR_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[3]);
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
$config = array('is_web' => false, 'base_path' => $root, 'include_path' => $root . '/include');
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
// Read the shipped allowlist rather than a copy; global_arrays.php itself
// needs the plugin API to load.
preg_match('/\\$allowed_proxy_headers\s*=\s*array\((.*?)\);/s', file_get_contents($root . '/include/global_arrays.php'), $block);
preg_match_all("/'([^']+)'/", $block[1], $names);
$allowed_proxy_headers = $names[1];

$results = array();
foreach (json_decode(file_get_contents($argv[4]), true, 512, JSON_THROW_ON_ERROR) as $name => $case) {
    $config['proxy_headers'] = $case['headers'];
    $config['proxy_trusted_addresses'] = $case['trusted'];
    $_SERVER = $case['server'];
    $results[$name] = get_client_addr();
}
echo json_encode($results, JSON_THROW_ON_ERROR);
PHP;

    file_put_contents($directory . '/probe.php', $program);
    file_put_contents($directory . '/cases.json', json_encode($cases, JSON_THROW_ON_ERROR));
    $command = array(
        PHP_BINARY,
        '-d', 'error_reporting=24575',
        '-d', 'pcov.directory=' . $root,
        '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
        $directory . '/probe.php',
        $root,
        $coverage === null ? 'plain' : 'coverage',
        $directory,
        $directory . '/cases.json',
    );

    try {
        $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start client address probe');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if ($status !== 0 || $stderr !== '') {
            throw new RuntimeException($stderr . $stdout);
        }

        if ($coverage !== null) {
            foreach (glob($directory . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

test('forwarded client addresses are trusted only from configured proxies', function () {
    $proxy = array('192.0.2.10');
    $xff   = array('HTTP_X_FORWARDED_FOR');
    $cases = array(
        'no peer'              => array('headers' => $xff, 'trusted' => $proxy, 'server' => array()),
        'invalid peer'         => array('headers' => $xff, 'trusted' => $proxy, 'server' => array('REMOTE_ADDR' => 'not-an-ip')),
        'legacy true'          => array('headers' => true, 'trusted' => array(), 'server' => array('REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5')),
        'untrusted peer'       => array('headers' => $xff, 'trusted' => $proxy, 'server' => array('REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5')),
        'trusted not array'    => array('headers' => $xff, 'trusted' => '192.0.2.10', 'server' => array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5')),
        'cidr ignored'         => array('headers' => $xff, 'trusted' => array('192.0.2.0/24', 42), 'server' => array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5')),
        'prefix is not match'  => array('headers' => $xff, 'trusted' => array('192.0.2.1'), 'server' => array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5')),
        'trusted proxy'        => array('headers' => $xff, 'trusted' => $proxy, 'server' => array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => ' 203.0.113.5 ')),
        'ipv6 proxy spelling'  => array('headers' => $xff, 'trusted' => array('2001:0db8:0:0::1'), 'server' => array('REMOTE_ADDR' => '2001:db8::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::5')),
        'address chain'        => array('headers' => $xff, 'trusted' => $proxy, 'server' => array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5, 198.51.100.7')),
        'invalid forwarded'    => array('headers' => $xff, 'trusted' => $proxy, 'server' => array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => 'localhost')),
        'missing header'       => array('headers' => $xff, 'trusted' => $proxy, 'server' => array('REMOTE_ADDR' => '192.0.2.10')),
        'two headers'          => array('headers' => array('HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP'), 'trusted' => $proxy, 'server' => array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5')),
        'remote addr header'   => array('headers' => array('REMOTE_ADDR'), 'trusted' => $proxy, 'server' => array('REMOTE_ADDR' => '192.0.2.10')),
        'header not allowed'   => array('headers' => array('HTTP_X_EVIL'), 'trusted' => $proxy, 'server' => array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_EVIL' => '203.0.113.5')),
        'headers not array'    => array('headers' => true, 'trusted' => $proxy, 'server' => array('REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5')),
    );

    $result = runClientAddrProbe($this->getTestResultObject()->getCodeCoverage(), $cases);

    expect($result)->toBe(array(
        'no peer'              => false,
        'invalid peer'         => false,
        'legacy true'          => '198.51.100.7',
        'untrusted peer'       => '198.51.100.7',
        'trusted not array'    => '192.0.2.10',
        'cidr ignored'         => '192.0.2.10',
        'prefix is not match'  => '192.0.2.10',
        'trusted proxy'        => '203.0.113.5',
        'ipv6 proxy spelling'  => '2001:db8::5',
        'address chain'        => false,
        'invalid forwarded'    => false,
        'missing header'       => false,
        'two headers'          => false,
        'remote addr header'   => false,
        'header not allowed'   => false,
        'headers not array'    => false,
    ));
});
