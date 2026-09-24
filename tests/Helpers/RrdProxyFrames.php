<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// For the fake RRDtool proxies in the child processes of the proxy tests. One
// throwaway key plays both the client and the proxy, so a test can read what
// the client sent and answer it without a second key pair.

function rrd_proxy_test_key(): string
{
    return (string) phpseclib4\Crypt\RSA::createKey(2048);
}

function rrd_proxy_test_public_key(string $private_key): string
{
    return (string) phpseclib4\Crypt\RSA::loadPrivateKey($private_key)->getPublicKey();
}

/** Decrypt each frame the client wrote, keeping the terminators so assertions read as the wire does. */
function rrd_proxy_test_plaintext($received)
{
    if (!is_string($received) || $received === '') {
        return $received;
    }
    $cipher = new Kadupul\Graphing\Infrastructure\Rrd\ProxyCipher();
    $frames = explode("_EOT_\r\n", $received);
    $tail = array_pop($frames);
    $plaintext = '';
    foreach ($frames as $frame) {
        $plaintext .= $cipher->decrypt($frame, $GLOBALS['proxy_key']) . "_EOT_\r\n";
    }

    return $plaintext . $tail;
}
