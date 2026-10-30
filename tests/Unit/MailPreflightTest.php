<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function mail_preflight_probe(array $scenario): array
{
    $scenario += array('mode' => 'tls', 'embedded' => false, 'certificate' => 'trusted', 'auth' => true,
        'bad_password' => false, 'refuse_tls' => false, 'handler' => false, 'bypass' => false,
        'reject_hello' => false, 'advertise_tls' => false);
    $directory = sys_get_temp_dir() . '/smtp-preflight-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $process = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/Fixtures/smtp-preflight-sink.php', dirname(__DIR__, 2),
            $directory, json_encode($scenario, JSON_THROW_ON_ERROR)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || $error !== '') throw new RuntimeException($error . $output);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
    }
}

test('mail preflight uses the configured SMTP negotiation and host port', function (string $mode, bool $embedded, bool $auth): void {
    $observed = mail_preflight_probe(array('mode' => $mode, 'embedded' => $embedded, 'auth' => $auth));
    expect($observed['client']['result'])->toBeTrue()
        ->and($observed['events'])->toContain('QUIT')
        ->and(in_array('AUTH', $observed['events'], true))->toBe($auth)
        ->and(in_array('STARTTLS', $observed['events'], true))->toBe($mode === 'tls')
        ->and(in_array('TLS established', $observed['events'], true))->toBe($mode !== 'none')
        ->and(array_count_values($observed['events'])['EHLO'])->toBe($mode === 'tls' ? 2 : 1)
        ->and($observed['events'])->not->toContain('Message received');
})->with(array('none', 'tls', 'ssl'))->with(array(false, true))->with(array(false, true));

test('untrusted or wrong-host SMTP certificates fail before authentication', function (string $mode, string $certificate, bool $embedded): void {
    $observed = mail_preflight_probe(array('mode' => $mode, 'certificate' => $certificate, 'embedded' => $embedded));
    expect($observed['client']['result'])->toBeString()
        ->and($observed['events'])->toContain('TLS attempt')
        ->and($observed['events'])->not->toContain('AUTH', 'Message received')
        ->and(json_encode($observed['client']))->not->toContain('fixture-password');
})->with(array('tls', 'ssl'))->with(array('untrusted', 'wrong-host'))->with(array(false, true));

test('a required STARTTLS refusal never falls back to plaintext authentication', function (bool $auth): void {
    $observed = mail_preflight_probe(array('refuse_tls' => true, 'auth' => $auth));
    expect($observed['client']['result'])->toBeString()
        ->and($observed['events'])->toContain('STARTTLS')
        ->and($observed['events'])->not->toContain('AUTH', 'TLS established', 'Message received');
})->with(array(false, true));

test('a rejected SMTP greeting remains a failed probe without authentication', function (): void {
    $observed = mail_preflight_probe(array('mode' => 'none', 'auth' => false, 'reject_hello' => true));
    expect($observed['client']['result'])->toContain('HELO failed:')
        ->and($observed['events'])->toContain('EHLO', 'HELO')
        ->and($observed['events'])->not->toContain('AUTH', 'Message received');
});

test('explicit plaintext mode does not automatically negotiate advertised STARTTLS', function (): void {
    $observed = mail_preflight_probe(array('mode' => 'none', 'advertise_tls' => true));
    expect($observed['client']['result'])->toBeTrue()
        ->and($observed['events'])->toContain('AUTH')
        ->and($observed['events'])->not->toContain('STARTTLS', 'TLS established');
});

test('the settings email handler delivers once with a successful or bypassed probe', function (string $mode, bool $embedded, bool $bypass): void {
    $observed = mail_preflight_probe(array('mode' => $mode, 'embedded' => $embedded, 'handler' => true, 'bypass' => $bypass));
    $counts = array_count_values($observed['events']);
    expect($observed['client']['output'])->toContain('Success!')
        ->and($counts['Connection'])->toBe($bypass ? 1 : 2)
        ->and($counts['Message received'])->toBe(1)
        ->and(json_encode($observed['client']))->not->toContain('fixture-password');
})->with(array('none', 'tls', 'ssl'))->with(array(false, true))->with(array(false, true));

test('a failed SMTP authentication blocks the settings test message', function (string $mode): void {
    $observed = mail_preflight_probe(array('mode' => $mode, 'handler' => true, 'bad_password' => true));
    expect($observed['client']['output'])->toContain('Message Not Sent due to ping failure.')
        ->and($observed['events'])->toContain('AUTH')
        ->and($observed['events'])->not->toContain('Message received')
        ->and(json_encode($observed['client']))->not->toContain('fixture-password');
})->with(array('none', 'tls', 'ssl'));
