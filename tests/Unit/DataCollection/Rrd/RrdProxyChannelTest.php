<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The RRDproxy client used to send 'setcnn encryption off' right after the
 * fingerprint check, and the proxy honours it, so every later RRDtool command
 * and its output crossed the network in clear text. A local stand-in proxy
 * speaks the same framing and cipher as Cacti/rrdproxy and records whether
 * each client packet arrived encrypted. It also answers file_exists the way
 * RRDproxy does: a PHP call on the arguments split at spaces, quotes kept.
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdGraphHarness.php';

$rrdProxyRoot = dirname(__DIR__, 4);

/**
 * @param array<int, array<int, string>> $calls - ('path', command, path) or ('quoted', command, path),
 *                                                where {work} in a path is the run's work directory
 * @param array<int, string>             $touch - files to create in the work directory first
 */
function rrd_proxy_channel_run(string $root, array $calls = array(), array $touch = array()) : array {
	require_once $root . '/include/vendor/autoload.php';

	$work = sys_get_temp_dir() . '/cacti-rrdp-' . bin2hex(random_bytes(6));
	mkdir($work, 0700);

	foreach ($touch as $name) {
		touch($work . '/' . $name);
	}

	$proxyKey  = phpseclib3\Crypt\RSA::createKey(2048);
	$clientKey = phpseclib3\Crypt\RSA::createKey(2048);

	$keys = array(
		'proxy_private'  => $proxyKey->toString('PKCS8'),
		'proxy_public'   => $proxyKey->getPublicKey()->toString('PKCS8'),
		'client_private' => $clientKey->toString('PKCS8'),
		'client_public'  => $clientKey->getPublicKey()->toString('PKCS8'),
		'fingerprint'    => $proxyKey->getPublicKey()->getFingerprint(),
		'root'           => $root,
		'work'           => $work,
		'calls'          => $calls,
	);

	file_put_contents($work . '/keys.json', json_encode($keys));

	$fsrc    = file_get_contents($root . '/lib/functions.php');
	$shipped = "<?php\n";

	foreach (array('cacti_escapeshellarg', 'cacti_has_control_chars', 'cacti_rrdtool_valid_path', 'cacti_rrdtool_valid_path_token') as $name) {
		$shipped .= cacti_test_rrd_function_source($fsrc, $name) . "\n\n";
	}

	file_put_contents($work . '/shipped.php', $shipped);

	$proxy = <<<'PHP'
<?php
$keys = json_decode(file_get_contents($argv[1]), true);
require $keys['root'] . '/include/vendor/autoload.php';

/* Cacti/rrdproxy lib/functions.php: fresh AES key per message, RSA-OAEP/SHA-1 wrap, zero IV, no MAC */
function proxy_encrypt($output, $rsa_key) {
	$rsa = phpseclib3\Crypt\PublicKeyLoader::loadPublicKey($rsa_key)->withPadding(phpseclib3\Crypt\RSA::ENCRYPTION_OAEP)->withHash('sha1')->withMGFHash('sha1');
	$aes = new phpseclib3\Crypt\Rijndael('cbc');
	$key = random_bytes(32);
	$aes->setKey($key);
	$aes->setIV(str_repeat("\0", 16));
	$ciphertext = base64_encode($aes->encrypt($output));
	$wrapped    = base64_encode($rsa->encrypt($key));

	return str_pad(dechex(strlen($wrapped)), 3, '0', STR_PAD_LEFT) . $wrapped . $ciphertext;
}

function proxy_decrypt($input, $private) {
	try {
		$length  = hexdec(substr($input, 0, 3));
		$wrapped = base64_decode(substr($input, 3, $length), true);
		$data    = base64_decode(substr($input, 3 + $length), true);

		if ($wrapped === false || $data === false) {
			return false;
		}

		$rsa = phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($private)->withPadding(phpseclib3\Crypt\RSA::ENCRYPTION_OAEP)->withHash('sha1')->withMGFHash('sha1');
		$aes = new phpseclib3\Crypt\Rijndael('cbc');
		$aes->setKey(substr($rsa->decrypt($wrapped), 0, 32));
		$aes->setIV(str_repeat("\0", 16));

		return $aes->decrypt($data);
	} catch (Throwable $e) {
		return false;
	}
}

function proxy_read_message($socket) {
	$buffer = '';

	while (strpos($buffer, "_EOT_\r\n") === false) {
		$chunk = socket_read($socket, 65536, PHP_BINARY_READ);

		if ($chunk === false || $chunk === '') {
			return false;
		}

		$buffer .= $chunk;
	}

	return substr($buffer, 0, strpos($buffer, "_EOT_\r\n"));
}

$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_bind($server, '127.0.0.1', 0);
socket_listen($server, 1);
socket_getsockname($server, $address, $port);
print $port . "\n";
fflush(STDOUT);

socket_set_option($server, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 20, 'usec' => 0));
$client = socket_accept($server);
socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 20, 'usec' => 0));

$client_public = trim(proxy_read_message($client));
socket_write($client, $keys['proxy_public'] . "_EOT_\r\n");

$encryption = true;
$packets    = array();

while (($raw = proxy_read_message($client)) !== false) {
	$plain = proxy_decrypt($raw, $keys['proxy_private']);
	$packets[] = array('encrypted' => $plain !== false, 'command' => $plain !== false ? $plain : $raw);
	$command   = $plain !== false ? $plain : $raw;

	if ($command === 'quit') {
		break;
	}

	if ($command === 'setcnn encryption off') {
		$reply = "% Encryption will be disabled.\nOK u:0.00 s:0.00 r:0.00";
		socket_write($client, proxy_encrypt($reply, $client_public) . "_EOT_\r\n");
		$encryption = false;

		continue;
	}

	/* Cacti/rrdproxy lib/client.php: trim, split the verb, then $options = explode(' ', $cmd_options)
	 * and call_user_func_array($cmd, $options); include/global.php: RRD_OK 'OK u:0.00', RRD_ERROR 'ERROR:' */
	$parts = explode(' ', trim($command), 2);

	if ($parts[0] === 'file_exists') {
		$status = call_user_func_array('file_exists', explode(' ', $parts[1] ?? ''));
		$reply  = ($status === true) ? 'OK u:0.00' : 'ERROR:';
	} else {
		$reply = "filename = \"t.rrd\"\nOK u:0.00 s:0.00 r:0.00";
	}

	socket_write($client, ($encryption ? proxy_encrypt($reply, $client_public) : $reply) . "_EOT_\r\n");
}

file_put_contents($argv[2], json_encode($packets));
PHP;

	$client = <<<'PHP'
<?php
$keys = json_decode(file_get_contents($argv[1]), true);
$port = (int) $argv[2];

define('CACTI_ESCAPE_CHARACTER', '"');
define('RRDTOOL_OUTPUT_NULL', 0);
define('RRDTOOL_OUTPUT_STDOUT', 1);
define('RRDTOOL_OUTPUT_STDERR', 2);
define('RRDTOOL_OUTPUT_GRAPH_DATA', 3);
define('RRDTOOL_OUTPUT_BOOLEAN', 4);
define('RRDTOOL_OUTPUT_RETURN_STDERR', 5);
define('POLLER_VERBOSITY_LOW', 2);
define('POLLER_VERBOSITY_DEBUG', 5);

$config = array('cacti_server_os' => 'unix', 'rra_path' => '/nonexistent/rra', 'library_path' => $keys['root'] . '/lib');

$options = array(
	'storage_location'    => '1',
	'rrdp_server'         => '127.0.0.1',
	'rrdp_port'           => $port,
	'rrdp_fingerprint'    => $keys['fingerprint'],
	'rsa_public_key'      => $keys['client_public'],
	'rsa_private_key'     => $keys['client_private'],
	'rrdp_load_balancing' => '',
);

function read_config_option($name, $force = false) {
	return $GLOBALS['options'][$name] ?? '';
}

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
}

require $keys['work'] . '/shipped.php';
require $keys['root'] . '/include/vendor/autoload.php';
require $keys['root'] . '/lib/rrd.php';

$rrdp   = __rrd_proxy_init();
$result = __rrd_proxy_execute('info t.rrd', false, RRDTOOL_OUTPUT_STDOUT, $rrdp);
$calls  = array();

foreach ($keys['calls'] as $call) {
	$path = str_replace('{work}', $keys['work'], $call[2]);

	if ($call[0] == 'path') {
		$calls[] = rrdtool_execute_path_command($call[1], $path, '', true, RRDTOOL_OUTPUT_BOOLEAN, $rrdp, 'RRDCHECK');
	} else {
		$calls[] = rrdtool_execute($call[1] . ' ' . rrdtool_quote_argument($path), true, RRDTOOL_OUTPUT_BOOLEAN, $rrdp, 'RRDCHECK');
	}
}

__rrd_proxy_close($rrdp);

print json_encode(array('connected' => $rrdp !== false, 'result' => $result, 'calls' => $calls, 'encryption' => $GLOBALS['encryption']));
PHP;

	file_put_contents($work . '/proxy.php', $proxy);
	file_put_contents($work . '/client.php', $client);

	$descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$server      = proc_open(array(PHP_BINARY, $work . '/proxy.php', $work . '/keys.json', $work . '/packets.json'), $descriptors, $serverPipes);
	$port        = (int) fgets($serverPipes[1]);

	$clientProcess = proc_open(array(PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', $work . '/client.php', $work . '/keys.json', (string) $port), $descriptors, $clientPipes);
	fclose($clientPipes[0]);
	$clientOut = stream_get_contents($clientPipes[1]) . stream_get_contents($clientPipes[2]);
	proc_close($clientProcess);

	fclose($serverPipes[0]);
	stream_get_contents($serverPipes[1]);
	proc_close($server);

	$packets = json_decode((string) @file_get_contents($work . '/packets.json'), true);

	foreach (glob($work . '/*') as $file) {
		unlink($file);
	}

	rmdir($work);

	return array('work' => $work, 'client' => json_decode($clientOut, true) ?? $clientOut, 'packets' => $packets ?? array());
}

test('the RRDproxy client keeps message encryption on after the handshake', function () use ($rrdProxyRoot) {
	$run = rrd_proxy_channel_run($rrdProxyRoot);

	expect($run['client'])->toBeArray()
		->and($run['client']['connected'])->toBeTrue()
		->and($run['client']['encryption'])->toBeTrue()
		->and($run['client']['result'])->toBe('filename = "t.rrd"');

	$commands = array_column($run['packets'], 'command');

	expect($commands)->not->toContain('setcnn encryption off')
		->and($commands)->toContain('info t.rrd')
		->and($commands)->toContain('quit');

	foreach ($run['packets'] as $packet) {
		expect($packet['encrypted'])->toBeTrue();
	}
})->skip(!extension_loaded('sockets'), 'the sockets extension is not loaded');

test('lib/rrd.php never asks the proxy to disable encryption', function () use ($rrdProxyRoot) {
	expect(file_get_contents($rrdProxyRoot . '/lib/rrd.php'))->not->toContain('setcnn encryption');
});

test('RRDproxy file_exists finds existing paths, including one with an apostrophe, and reports missing ones', function () use ($rrdProxyRoot) {
	$run = rrd_proxy_channel_run($rrdProxyRoot, array(
		array('path', 'file_exists', "{work}/it's.rrd"),
		array('path', 'file_exists', '{work}/plain.rrd'),
		array('path', 'file_exists', "{work}/gone's.rrd"),
		array('path', 'file_exists', '{work}/missing.rrd'),
		array('quoted', 'file_exists', "{work}/it's.rrd"),
		array('quoted', 'file_exists', '{work}/plain.rrd'),
	), array("it's.rrd", 'plain.rrd'));

	expect($run['client'])->toBeArray()
		->and($run['client']['connected'])->toBeTrue();

	/* the bare path command RRDcheck now sends */
	expect(array_slice($run['client']['calls'], 0, 4))->toBe(array(true, true, false, false));

	/* the quoted form RRDcheck sent before: RRDproxy keeps the quotes, so no path is found */
	expect(array_slice($run['client']['calls'], 4, 2))->toBe(array(false, false));

	$commands = array_column($run['packets'], 'command');

	expect($commands)->toContain('file_exists ' . $run['work'] . "/it's.rrd")
		->and($commands)->toContain('file_exists ' . $run['work'] . '/missing.rrd');

	foreach ($run['packets'] as $packet) {
		expect($packet['encrypted'])->toBeTrue();
	}
})->skip(!extension_loaded('sockets'), 'the sockets extension is not loaded');
