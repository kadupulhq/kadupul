<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Shared by the tests that talk to tests/Fixtures/rrd-fake-proxy.php.

/** A throwaway RSA pair in the PKCS#8 form rsa_check_keypair() stores, with its fingerprint. */
function rrd_proxy_interop_key(): array
{
    $key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
    openssl_pkey_export($key, $private);
    $details = openssl_pkey_get_details($key);
    // MD5 of the OpenSSH public key blob, which rrdproxy displays and administrators copy.
    $blob = pack('N', 7) . 'ssh-rsa';
    foreach (array($details['rsa']['e'], $details['rsa']['n']) as $integer) {
        $integer = ltrim($integer, "\0");
        $integer = (ord($integer[0]) & 0x80) ? "\0" . $integer : $integer;
        $blob .= pack('N', strlen($integer)) . $integer;
    }

    return array('private' => $private, 'public' => $details['key'], 'fingerprint' => implode(':', str_split(md5($blob), 2)));
}

/**
 * Start the fake proxy on $setup in $directory. Returns the process, its
 * stdout pipe and the port it listens on; the decrypted commands land in
 * $directory/commands.json once the client hangs up.
 */
function rrd_fake_proxy_start(string $directory, array $setup): array
{
    $root = dirname(__DIR__, 2);
    file_put_contents($directory . '/proxy.json', json_encode($setup + array(
        'autoload' => $root . '/include/vendor/autoload.php',
        'rrdproxy' => null,
        'key_reply' => 'key',
        'replies' => array(),
    ), JSON_THROW_ON_ERROR));
    $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', $root . '/tests/Fixtures/rrd-fake-proxy.php', $directory), array(1 => array('pipe', 'w'), 2 => array('file', $directory . '/proxy.stderr', 'w')), $pipes);
    $port = trim((string) fgets($pipes[1]));
    expect($port)->toMatch('/^\d+$/', (string) @file_get_contents($directory . '/proxy.stderr'));

    return array($process, $pipes[1], $port);
}

/** Wait for the fake proxy to finish and return the commands it received. */
function rrd_fake_proxy_finish(string $directory, $process, $stdout): array
{
    fclose($stdout);
    expect(proc_close($process))->toBe(0)->and(file_get_contents($directory . '/proxy.stderr'))->toBe('');

    return json_decode(file_get_contents($directory . '/commands.json'), true, 512, JSON_THROW_ON_ERROR);
}
