<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Child process for RrdProxyInteropTest. It runs one side's real encrypt() and
// decrypt(): Kadupul's from lib/rrd.php, or rrdproxy's from lib/functions.php
// in a checkout. The two define functions with the same names, so each side
// gets its own process.
//
// argv: request file. The request names the side, the autoloader, the keys,
// base64 payloads to encrypt and frames to decrypt; the reply is JSON on stdout.

$request = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);

require $request['autoload'];
if ($request['side'] === 'rrdproxy') {
    require $request['rrdproxy'] . '/lib/functions.php';
    $encryption = true;
    $rrdp_config['encryption']['private_key'] = $request['private_key'];
} else {
    function read_config_option($name)
    {
        return $name === 'rsa_private_key' ? $GLOBALS['request']['private_key'] : '';
    }
    require $request['root'] . '/include/global_constants.php';
    require $request['root'] . '/lib/rrd.php';
}

$reply = array('encrypted' => array(), 'decrypted' => array());
foreach ($request['encrypt'] as $payload) {
    $reply['encrypted'][] = encrypt(base64_decode($payload), $request['public_key']);
}
foreach ($request['decrypt'] as $frame) {
    $plaintext = decrypt($frame);
    $reply['decrypted'][] = $plaintext === false ? false : base64_encode($plaintext);
}

echo json_encode($reply, JSON_THROW_ON_ERROR);
