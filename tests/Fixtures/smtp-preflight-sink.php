<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function probe_reply($socket, string $text): bool {
	set_error_handler(static function (int $level, string $message): bool {
		if (preg_match('/fwrite\(\).*(Broken pipe|Connection reset|closed|SSL operation failed with code 5)/i', $message)) return true;
		throw new ErrorException($message, 0, $level);
	});
	try {
		for ($offset = 0; $offset < strlen($text); $offset += $written) {
			$written = fwrite($socket, substr($text, $offset));
			if ($written === false || $written === 0) return false;
		}
		return true;
	} finally {
		restore_error_handler();
	}
}

function probe_connection($socket, array $scenario, array &$events): void {
	stream_set_timeout($socket, 3);
	$encrypted = false;
	if ($scenario['mode'] === 'ssl') {
		$events[] = 'TLS attempt';
		$encrypted = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
		if (!$encrypted) return;
		$events[] = 'TLS established';
	}
	if (!probe_reply($socket, "220 fixture.example.test ready\r\n")) return;
	$data = false;
	$auth = false;
	$credentials = static function (string $encoded) use (&$encrypted, $scenario): bool {
		$decoded = base64_decode(trim($encoded), true);
		$fields = $decoded === false ? array() : explode("\0", $decoded);
		return count($fields) === 3 && $fields[1] === 'fixture-user' && $fields[2] === 'fixture-password'
			&& ($encrypted || $scenario['mode'] === 'none');
	};
	while (($line = fgets($socket)) !== false) {
		if ($line !== '' && ord($line[0]) === 22) {
			$events[] = 'Unexpected implicit handshake';
			return;
		}
		if ($auth) {
			$auth = false;
			if (!probe_reply($socket, $credentials($line) ? "235 accepted\r\n" : "535 authentication rejected\r\n")) return;
			continue;
		}
		if ($data) {
			if ($line === ".\r\n") {
				$events[] = 'Message received';
				$data = false;
				if (!probe_reply($socket, "250 message captured locally\r\n")) return;
			}
			continue;
		}
		$verb = strtoupper(strtok($line, " \r\n"));
		$events[] = $verb;
		switch ($verb) {
			case 'EHLO':
			case 'HELO':
				if ($scenario['reject_hello']) {
					if (!probe_reply($socket, "550 greeting rejected\r\n")) return;
					break;
				}
				if (!$encrypted && $scenario['mode'] === 'none' && $scenario['advertise_tls']) {
					if (!probe_reply($socket, "250-fixture.example.test\r\n250-STARTTLS\r\n250 AUTH PLAIN\r\n")) return;
					break;
				}
				$capabilities = $encrypted || $scenario['mode'] === 'none' ? 'AUTH PLAIN' : 'STARTTLS';
				if (!probe_reply($socket, "250-fixture.example.test\r\n250 $capabilities\r\n")) return;
				break;
			case 'STARTTLS':
				if ($scenario['refuse_tls']) {
					if (!probe_reply($socket, "454 TLS unavailable\r\n")) return;
					break;
				}
				if (!probe_reply($socket, "220 begin TLS\r\n")) return;
				$events[] = 'TLS attempt';
				if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) return;
				$encrypted = true;
				$events[] = 'TLS established';
				break;
			case 'AUTH':
				if (trim($line) === 'AUTH PLAIN') {
					$auth = true;
					if (!probe_reply($socket, "334 \r\n")) return;
					break;
				}
				$valid = $credentials(substr($line, strlen('AUTH PLAIN ')));
				if (!probe_reply($socket, $valid ? "235 accepted\r\n" : "535 authentication rejected\r\n")) return;
				break;
			case 'DATA':
				$data = true;
				if (!probe_reply($socket, "354 send content\r\n")) return;
				break;
			case 'QUIT':
				probe_reply($socket, "221 closing\r\n");
				return;
			default:
				if (!probe_reply($socket, "250 accepted\r\n")) return;
		}
	}
}

$root = $argv[1];
$directory = $argv[2];
$scenario = json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR);
foreach (array('server', 'other') as $identity) {
	$address = $identity === 'server' && $scenario['certificate'] === 'wrong-host' ? '192.0.2.1' : '127.0.0.1';
	$configuration = $directory . '/' . $identity . '.cnf';
	file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ext]\nsubjectAltName=IP:$address\nbasicConstraints=critical,CA:TRUE\n");
	$options = array('config' => $configuration, 'digest_alg' => 'sha256', 'private_key_bits' => 2048);
	$key = openssl_pkey_new($options);
	$request = openssl_csr_new(array('commonName' => $address), $key, $options);
	$certificate = openssl_csr_sign($request, null, $key, 1, $options);
	if (!openssl_x509_export_to_file($certificate, $directory . '/' . $identity . '.crt')
		|| !openssl_pkey_export_to_file($key, $directory . '/' . $identity . '.key', null, $options)) {
		throw new RuntimeException('Cannot prepare fixture trust material');
	}
}
$context = stream_context_create(array('ssl' => array('local_cert' => $directory . '/server.crt',
	'local_pk' => $directory . '/server.key', 'verify_peer' => false)));
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!is_resource($listener)) throw new RuntimeException($error);
$port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
$pid = pcntl_fork();
if ($pid === -1) throw new RuntimeException('Cannot start fixture listener');
if ($pid === 0) {
	$events = array();
	try {
		$deadline = microtime(true) + 15;
		while (microtime(true) < $deadline) {
			$read = array($listener);
			$write = $except = array();
			if (stream_select($read, $write, $except, 0, 100000) === 0) {
				if (is_file($directory . '/done')) break;
				continue;
			}
			$socket = stream_socket_accept($listener, 0);
			if (!is_resource($socket)) throw new RuntimeException('Cannot accept fixture connection');
			$events[] = 'Connection';
			try { probe_connection($socket, $scenario, $events); } finally { fclose($socket); }
		}
		file_put_contents($directory . '/events.json', json_encode($events, JSON_THROW_ON_ERROR));
		fclose($listener);
		exit(0);
	} catch (Throwable $failure) {
		file_put_contents($directory . '/server-error', (string) $failure);
		exit(2);
	}
}
fclose($listener);
$scenario['settings'] = array('settings_how' => '2', 'settings_smtp_host' => '127.0.0.1' . ($scenario['embedded'] ? ':' . $port : ''),
	'settings_smtp_port' => $scenario['embedded'] ? 1 : $port, 'settings_smtp_secure' => $scenario['mode'],
	'settings_smtp_timeout' => 2, 'settings_smtp_username' => $scenario['auth'] ? 'fixture-user' : '',
	'settings_smtp_password' => $scenario['bad_password'] ? 'incorrect-fixture-password' : 'fixture-password',
	'settings_ping_mail' => $scenario['bypass'] ? '1' : '0', 'settings_from_email' => 'sender@example.invalid',
	'settings_from_name' => 'Fixture', 'settings_test_email' => 'recipient@example.invalid', 'settings_wordwrap' => '76');
$process = proc_open(array(PHP_BINARY, '-d', 'zend.exception_ignore_args=1', '-d', 'openssl.cafile=' . $directory . ($scenario['certificate'] === 'untrusted' ? '/other.crt' : '/server.crt'),
	'-d', 'openssl.capath=', __DIR__ . '/smtp-preflight-client.php', $root),
	array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
try {
	if (!is_resource($process)) throw new RuntimeException('Cannot start native SMTP client');
	fwrite($pipes[0], json_encode($scenario, JSON_THROW_ON_ERROR));
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	touch($directory . '/done');
	pcntl_waitpid($pid, $serverStatus);
	if (!pcntl_wifexited($serverStatus) || pcntl_wexitstatus($serverStatus) !== 0) {
		throw new RuntimeException(file_get_contents($directory . '/server-error'));
	}
	if ($status !== 0 || $error !== '') throw new RuntimeException($error . $output);
	echo json_encode(array('client' => json_decode($output, true, 512, JSON_THROW_ON_ERROR),
		'events' => json_decode(file_get_contents($directory . '/events.json'), true, 512, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
} finally {
	if (pcntl_waitpid($pid, $unused, WNOHANG) === 0) {
		posix_kill($pid, SIGTERM);
		pcntl_waitpid($pid, $unused);
	}
}
