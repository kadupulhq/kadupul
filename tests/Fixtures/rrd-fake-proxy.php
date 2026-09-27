<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// A one-connection RRDtool proxy for RrdProxyInteropTest. It follows the
// session in rrdproxy's lib/client.php at 54aad57: the client key and its MD5
// fingerprint check (lines 139-175), a decryption failure (177-184), setenv
// and setcnn (306-350), quit (296-299), and replies of one or more packets
// separated by _EOP_ and ended by _EOT_. With an rrdproxy checkout it frames
// with rrdproxy's own encrypt() and decrypt(); otherwise with Kadupul's.
//
// argv: work directory holding proxy.json. The port goes to stdout and the
// decrypted commands to commands.json.

$directory = $argv[1];
$setup = json_decode(file_get_contents($directory . '/proxy.json'), true, 512, JSON_THROW_ON_ERROR);

require $setup['autoload'];
if ($setup['rrdproxy'] !== null) {
    require $setup['rrdproxy'] . '/lib/functions.php';
    $encryption = true;
    $rrdp_config['encryption']['private_key'] = $setup['proxy_private_key'];
} else {
    function encrypt($output, $rsa_key)
    {
        return (new Kadupul\Graphing\Infrastructure\Rrd\ProxyCipher())->encrypt($output, $rsa_key);
    }

    function decrypt($input)
    {
        try {
            return (new Kadupul\Graphing\Infrastructure\Rrd\ProxyCipher())->decrypt($input, $GLOBALS['setup']['proxy_private_key']);
        } catch (Throwable $e) {
            return false;
        }
    }
}

$end_of_packet = "_EOP_\r\n";
$end_of_sequence = "_EOT_\r\n";
$commands = array();

function fake_proxy_write($socket, string $data): void
{
    while ($data !== '') {
        $written = socket_write($socket, $data);
        if ($written === false) {
            return;
        }
        $data = substr($data, $written);
    }
}

/** One sequence from the client, or null when it hangs up. */
function fake_proxy_read($socket, string &$input)
{
    while (($end = strpos($input, "_EOT_\r\n")) === false) {
        // A slow proxy takes the request a few bytes at a time.
        $recv = @socket_read($socket, $GLOBALS['setup']['read_chunk'] ?? 100000, PHP_BINARY_READ);
        if (isset($GLOBALS['setup']['read_chunk'])) {
            usleep(1000);
        }
        if ($recv === false || $recv === '') {
            return null;
        }
        $input .= $recv;
    }
    $sequence = substr($input, 0, $end);
    $input = substr($input, $end + 7);

    return $sequence;
}

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_bind($server, '127.0.0.1', 0);
socket_listen($server, 1);
socket_getsockname($server, $address, $port);
fwrite(STDOUT, $port . "\n");
fflush(STDOUT);

$read = array($server);
$write = $except = null;
if (socket_select($read, $write, $except, 20) !== 1) {
    exit(4);
}
$client = socket_accept($server);
socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 20, 'usec' => 0));
$input = '';

$client_key = fake_proxy_read($client, $input);
$authenticated = false;
if ($client_key !== null && strlen($client_key) <= 16384 && strpos($client_key, '-----BEGIN PUBLIC KEY-----') !== false) {
    try {
        $fingerprint = phpseclib4\Crypt\RSA::loadPublicKey($client_key)->getFingerprint('md5');
        $authenticated = hash_equals(strtolower($setup['client_fingerprint']), strtolower($fingerprint));
    } catch (Throwable $e) {
    }
}

// Anything but a clean key reply ends the session, as a broken proxy would.
$replies = array(
    'oversize' => str_repeat('A', 20000),
    'trailing' => $setup['proxy_public_key'] . $end_of_sequence . 'extra',
    'not-a-key' => "-----BEGIN PUBLIC KEY-----\nnot a key\n-----END PUBLIC KEY-----" . $end_of_sequence,
    'close' => substr($setup['proxy_public_key'], 0, 100),
);
if (!$authenticated) {
    fake_proxy_write($client, 'Authentication failed' . $end_of_sequence);
} elseif (isset($replies[$setup['key_reply']])) {
    fake_proxy_write($client, $replies[$setup['key_reply']]);
} else {
    fake_proxy_write($client, $setup['proxy_public_key'] . $end_of_sequence);
    while (($sequence = fake_proxy_read($client, $input)) !== null) {
        $command = decrypt($sequence);
        if ($command === false) {
            $commands[] = false;
            fake_proxy_write($client, 'Decryption error' . $end_of_sequence);
            break;
        }
        if (strpos($command, "\x1f\x8b") === 0) {
            $command = gzdecode($command);
        }
        $command = trim($command);
        $commands[] = $command;
        $verb = strtok($command, ' ');
        if ($verb === 'quit' || $verb === ($setup['hang_up_on'] ?? null)) {
            break;
        }
        if ($verb === 'setenv') {
            fake_proxy_write($client, encrypt('OK u:0.00', $client_key) . $end_of_sequence);
        } elseif ($verb === 'setcnn') {
            // rrdproxy knows only the timeout parameter.
            fake_proxy_write($client, encrypt('ERROR: % setcnn <parameter> <value>', $client_key) . $end_of_sequence);
        } else {
            // rrdproxy compresses each packet of a long reply on its own.
            $packets = $setup['replies'][$verb] ?? array('OK u:0.00 s:0.00 r:0.00');
            $last = array_pop($packets);
            foreach ($packets as $packet) {
                fake_proxy_write($client, encrypt(gzencode($packet, 1), $client_key) . $end_of_packet);
            }
            fake_proxy_write($client, encrypt($last, $client_key) . $end_of_sequence);
        }
    }
}

file_put_contents($directory . '/commands.json', json_encode($commands, JSON_THROW_ON_ERROR));
socket_close($client);
socket_close($server);
