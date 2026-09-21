<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('main uses phpseclib 4 and preserves stored RSA keys', function (): void {
    $root = dirname(__DIR__, 2);
    $manifest = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($manifest['require']['php'])->toBe('>=8.4')
        ->and($manifest['require']['phpseclib/phpseclib'])->toBe('^4.0')
        ->and($manifest['config']['platform']['php'])->toBe('8.4.0');
    $program = <<<'PHP'
require $argv[1] . '/include/vendor/autoload.php';
require $argv[1] . '/lib/auth.php';
$settings = array();
$writes = 0;
function read_config_option($name) { return $GLOBALS['settings'][$name] ?? ''; }
function db_execute_prepared($sql, $params) {
    $GLOBALS['writes']++;
    $GLOBALS['settings'] = array_combine(array('rsa_public_key', 'rsa_private_key', 'rsa_fingerprint'), array_map('strval', $params));
}
rsa_check_keypair();
$original = $settings;
rsa_check_keypair();
$private = phpseclib4\Crypt\RSA::loadPrivateKey($settings['rsa_private_key']);
$public = phpseclib4\Crypt\RSA::loadPublicKey($settings['rsa_public_key']);
$message = 'Kadupul RSA compatibility fixture';
// Existing PEM keys must be accepted without rotating stored application keys.
$legacy = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
openssl_pkey_export($legacy, $pem);
$loaded = phpseclib4\Crypt\RSA::loadPrivateKey($pem)->withPadding(phpseclib4\Crypt\RSA::SIGNATURE_PKCS1)->withHash('sha256');
echo json_encode(array(
    'writes' => $writes,
    'unchanged' => $original === $settings,
    'bits' => $private->getLength(),
    'fingerprint' => $public->getFingerprint() === $settings['rsa_fingerprint'],
    'signature' => $public->verify($message, $private->sign($message)),
    'roundtrip' => $private->decrypt($public->encrypt($message)) === $message,
    'existing_key' => openssl_verify($message, $loaded->sign($message), openssl_pkey_get_details($legacy)['key'], OPENSSL_ALGO_SHA256),
));
PHP;
    $process = proc_open(array(PHP_BINARY, '-r', $program, $root), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    expect(is_resource($process))->toBeTrue();
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $stderr)->and($stderr)->toBe('');
    expect(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR))->toBe(array(
        'writes' => 1, 'unchanged' => true, 'bits' => 2048, 'fingerprint' => true,
        'signature' => true, 'roundtrip' => true, 'existing_key' => 1,
    ));
});
