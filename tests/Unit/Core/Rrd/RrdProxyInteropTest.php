<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// The RRDtool proxy client against three counterparts: rrdproxy's own frame
// code, the OpenSSL command line, which shares no code with either side, and a
// fake proxy socket for the session. rrdproxy is not a dependency, so its cases
// run only when RRDPROXY_SOURCE names a checkout; RRDPROXY_AUTOLOAD names the
// Composer autoloader holding the phpseclib it requires, by default the one in
// the checkout.

require_once dirname(__DIR__, 3) . '/Helpers/RrdFakeProxy.php';

/** The rrdproxy checkout and autoloader, or null when the environment names none. */
function rrd_proxy_interop_rrdproxy(): ?array
{
    $source = getenv('RRDPROXY_SOURCE');
    if ($source === false || $source === '' || !is_file($source . '/lib/functions.php')) {
        return null;
    }
    $autoload = getenv('RRDPROXY_AUTOLOAD') ?: $source . '/vendor/autoload.php';

    return is_file($autoload) ? array('source' => $source, 'autoload' => $autoload) : null;
}

/** Run PHP on $program (a file, or source when $inline) and return its stdout, merging child coverage. */
function rrd_proxy_interop_php($test, string $program, array $arguments = array(), bool $inline = false): string
{
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/rrd-proxy-interop-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $coverage = $test->getTestResultObject()->getCodeCoverage();
    try {
        if ($inline) {
            $bootstrap = '<?php ';
            if ($coverage !== null) {
                $bootstrap .= 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($directory, true) . ');require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
            }
            file_put_contents($directory . '/probe.php', $bootstrap . $program);
            $program = $directory . '/probe.php';
        }
        $process = proc_open(array_merge(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $program), $arguments), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');
        if ($coverage !== null) {
            foreach (glob($directory . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }

        return $output;
    } finally {
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

/** Encrypt and decrypt on one side, in its own process, through its real encrypt() and decrypt(). */
function rrd_proxy_interop_frames($test, string $side, array $keys, array $payloads, array $frames): array
{
    $root = dirname(__DIR__, 4);
    $rrdproxy = rrd_proxy_interop_rrdproxy();
    $request = tempnam(sys_get_temp_dir(), 'rrd-proxy-frames-');
    file_put_contents($request, json_encode(array(
        'side' => $side, 'root' => $root,
        'autoload' => $side === 'rrdproxy' ? $rrdproxy['autoload'] : $root . '/include/vendor/autoload.php',
        'rrdproxy' => $rrdproxy['source'] ?? null,
        'private_key' => $keys['private'], 'public_key' => $keys['public'],
        'encrypt' => array_map('base64_encode', $payloads), 'decrypt' => $frames,
    ), JSON_THROW_ON_ERROR));
    try {
        $program = 'require ' . var_export($root . '/tests/Fixtures/rrd-proxy-frames.php', true) . ';';
        $reply = json_decode(rrd_proxy_interop_php($test, $program, array($request), true), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        unlink($request);
    }
    $reply['decrypted'] = array_map(function ($plaintext) {
        return $plaintext === false ? false : base64_decode($plaintext);
    }, $reply['decrypted']);

    return $reply;
}

function rrd_proxy_interop_payloads(): array
{
    // Block boundaries for the CBC padding, a gzip header and bytes that are not UTF-8.
    return array('', 'x', str_repeat('a', 15), str_repeat('b', 16), str_repeat('c', 17), gzencode('OK u:0.00', 1), random_bytes(100000));
}

/** Run a proxy session: the fake proxy in one process, the client in another. */
function rrd_proxy_interop_session($test, ?array $rrdproxy, array $client, array $proxy): array
{
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/rrd-fake-proxy-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $server = null;
    try {
        list($server, $stdout, $port) = rrd_fake_proxy_start($directory, $proxy + array(
            'autoload' => $rrdproxy['autoload'] ?? $root . '/include/vendor/autoload.php',
            'rrdproxy' => $rrdproxy['source'] ?? null,
        ));
        $options = $client + array('storage_location' => 1, 'rrdp_server' => '127.0.0.1', 'rrdp_port' => $port);
        $program = '$root=' . var_export($root, true) . ';$options=' . var_export($options, true) . ';' . <<<'PHP'
$config = array('rra_path' => '/fixture');
require $root . '/include/global_constants.php';
require $root . '/include/vendor/autoload.php';
$logged = array();
function cacti_log($message, ...$args) { $GLOBALS['logged'][] = $message; }
function read_config_option($key) { return $GLOBALS['options'][$key] ?? ''; }
require $root . '/lib/rrd.php';
$rrdp = rrd_init();
$output = $rrdp === false ? null : rrdtool_execute('info /fixture/sample.rrd', false, RRDTOOL_OUTPUT_STDOUT, $rrdp);
if ($rrdp !== false) {
    rrd_close($rrdp);
}
echo json_encode(array('connected' => $rrdp !== false, 'output' => $output, 'problems' => array_values(array_filter($logged, function ($message) {
    return strpos($message, 'CACTI2RRDP ERROR') === 0 || strpos($message, 'CACTI2RRDP WARNING') === 0;
}))));
PHP;
        $result = json_decode(rrd_proxy_interop_php($test, $program, array(), true), true, 512, JSON_THROW_ON_ERROR);
        $result['proxy_received'] = rrd_fake_proxy_finish($directory, $server, $stdout);

        return $result;
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
        }
        foreach (glob($directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

test('proxy frames use RSA-OAEP with SHA-256 and AES-256-CBC with a zero IV, read by OpenSSL', function () {
    $openssl = function (array $arguments, string $input = '') {
        $process = @proc_open(array_merge(array(getenv('OPENSSL_BIN') ?: 'openssl'), $arguments), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) {
            return false;
        }
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? $output : false;
    };
    if ($openssl(array('version')) === false) {
        $this->markTestSkipped('The openssl command is required.');
    }
    $keys = rrd_proxy_interop_key();
    $private = tempnam(sys_get_temp_dir(), 'rrd-proxy-key-');
    $public = tempnam(sys_get_temp_dir(), 'rrd-proxy-key-');
    file_put_contents($private, $keys['private']);
    file_put_contents($public, $keys['public']);
    $oaep = array('-pkeyopt', 'rsa_padding_mode:oaep', '-pkeyopt', 'rsa_oaep_md:sha256', '-pkeyopt', 'rsa_mgf1_md:sha256');
    $wrap = function (string $key) use ($openssl, $public, $oaep) {
        return base64_encode($openssl(array_merge(array('pkeyutl', '-encrypt', '-pubin', '-inkey', $public), $oaep), $key));
    };
    try {
        $payloads = rrd_proxy_interop_payloads();
        foreach (rrd_proxy_interop_frames($this, 'kadupul', $keys, $payloads, array())['encrypted'] as $index => $frame) {
            // A 2048-bit key wraps to 256 bytes, 344 in base64.
            expect(substr($frame, 0, 3))->toBe('158');
            $key = $openssl(array_merge(array('pkeyutl', '-decrypt', '-inkey', $private), $oaep), base64_decode(substr($frame, 3, 344), true));
            expect(strlen($key))->toBe(32);
            expect(openssl_decrypt(base64_decode(substr($frame, 347), true), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, str_repeat("\0", 16)))->toBe($payloads[$index]);
        }

        $frames = array();
        foreach ($payloads as $payload) {
            $key = random_bytes(32);
            $wrapped = $wrap($key);
            $frames[] = sprintf('%03x', strlen($wrapped)) . $wrapped . base64_encode(openssl_encrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, str_repeat("\0", 16)));
        }
        // A frame with a 16-byte key is not one rrdproxy writes, and is refused.
        $key = random_bytes(16);
        $wrapped = $wrap($key);
        $frames[] = sprintf('%03x', strlen($wrapped)) . $wrapped . base64_encode(openssl_encrypt('short key', 'aes-128-cbc', $key, OPENSSL_RAW_DATA, str_repeat("\0", 16)));
        expect(rrd_proxy_interop_frames($this, 'kadupul', $keys, array(), $frames)['decrypted'])->toBe(array_merge($payloads, array(false)));
    } finally {
        unlink($private);
        unlink($public);
    }
});

test('Kadupul and rrdproxy each read the frames the other writes', function () {
    if (rrd_proxy_interop_rrdproxy() === null) {
        $this->markTestSkipped('Set RRDPROXY_SOURCE to an rrdproxy checkout to run it.');
    }
    $keys = rrd_proxy_interop_key();
    $payloads = rrd_proxy_interop_payloads();
    $from_kadupul = rrd_proxy_interop_frames($this, 'kadupul', $keys, $payloads, array());
    $from_rrdproxy = rrd_proxy_interop_frames($this, 'rrdproxy', $keys, $payloads, $from_kadupul['encrypted']);
    expect($from_rrdproxy['decrypted'])->toBe($payloads);
    expect(rrd_proxy_interop_frames($this, 'kadupul', $keys, array(), $from_rrdproxy['encrypted'])['decrypted'])->toBe($payloads);
    // Each side refuses a plaintext reply and a frame for another key.
    $other = rrd_proxy_interop_key();
    $foreign = rrd_proxy_interop_frames($this, 'kadupul', $other, array('OK u:0.00'), array())['encrypted'][0];
    expect(rrd_proxy_interop_frames($this, 'kadupul', $keys, array(), array('OK u:0.00', $foreign))['decrypted'])->toBe(array(false, false));
    expect(rrd_proxy_interop_frames($this, 'rrdproxy', $keys, array(), array('OK u:0.00', $foreign))['decrypted'])->toBe(array(false, false));
});

test('malformed frames and keys that are not RSA are refused', function () {
    $keys = rrd_proxy_interop_key();
    $good = rrd_proxy_interop_frames($this, 'kadupul', $keys, array('OK u:0.00'), array())['encrypted'][0];
    $frames = array(
        'zz' . substr($good, 2),
        '158' . substr($good, 3, 100),
        '000' . substr($good, 3),
        substr($good, 0, 347) . '!!!!',
        substr($good, 0, 3) . str_repeat('A', 344) . substr($good, 347),
    );
    expect(rrd_proxy_interop_frames($this, 'kadupul', $keys, array(), $frames)['decrypted'])->toBe(array(false, false, false, false, false));
    $ec = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
    openssl_pkey_export($ec, $ec_private);
    $ec_keys = array('private' => $ec_private, 'public' => openssl_pkey_get_details($ec)['key']);
    expect(rrd_proxy_interop_frames($this, 'kadupul', $ec_keys, array('OK u:0.00'), array($good)))->toBe(array('encrypted' => array(false), 'decrypted' => array(false)));
});

test('a proxy session exchanges keys, checks the fingerprint and carries commands both ways', function ($counterpart) {
    $rrdproxy = rrd_proxy_interop_rrdproxy();
    if ($counterpart === 'rrdproxy' && $rrdproxy === null) {
        $this->markTestSkipped('Set RRDPROXY_SOURCE to an rrdproxy checkout to run it.');
    }
    $client = rrd_proxy_interop_key();
    $proxy = rrd_proxy_interop_key();
    $long = str_repeat("ds[traffic_in].value = 1.0000000000e+00\n", 2000);
    $result = rrd_proxy_interop_session($this, $counterpart === 'rrdproxy' ? $rrdproxy : null, array(
        'rsa_public_key' => $client['public'], 'rsa_private_key' => $client['private'],
        // Stored by hand, so case and blanks around it do not matter.
        'rrdp_fingerprint' => ' ' . strtoupper($proxy['fingerprint']) . ' ',
        'path_rrdtool_default_font' => '/usr/share/fonts/DejaVuSans.ttf',
    ), array(
        'proxy_private_key' => $proxy['private'], 'proxy_public_key' => $proxy['public'], 'client_fingerprint' => $client['fingerprint'],
        'replies' => array('info' => array($long, "last_update = 1700000060\nOK u:0.00 s:0.00 r:0.00\n")),
    ));
    expect($result)->toBe(array(
        'connected' => true,
        'output' => $long . 'last_update = 1700000060',
        'problems' => array(),
        'proxy_received' => array('setenv RRD_DEFAULT_FONT /usr/share/fonts/DejaVuSans.ttf', 'setcnn encryption off', 'info ./sample.rrd', 'quit'),
    ));
})->with(array('kadupul', 'rrdproxy'));

test('a failed key exchange connects to nothing and sends no command', function ($client_change, $proxy_change, $problem) {
    $client = rrd_proxy_interop_key();
    $proxy = rrd_proxy_interop_key();
    $result = rrd_proxy_interop_session($this, null, $client_change + array(
        'rsa_public_key' => $client['public'], 'rsa_private_key' => $client['private'], 'rrdp_fingerprint' => $proxy['fingerprint'],
    ), $proxy_change + array(
        'proxy_private_key' => $proxy['private'], 'proxy_public_key' => $proxy['public'], 'client_fingerprint' => $client['fingerprint'],
    ));
    expect($result)->toBe(array('connected' => false, 'output' => null, 'problems' => array($problem), 'proxy_received' => array()));
})->with(array(
    'wrong fingerprint' => array(array('rrdp_fingerprint' => '00:11:22:33:44:55:66:77:88:99:aa:bb:cc:dd:ee:ff'), array(), 'CACTI2RRDP ERROR: Mismatch RSA Fingerprint.'),
    'no stored fingerprint' => array(array('rrdp_fingerprint' => ''), array(), 'CACTI2RRDP ERROR: Mismatch RSA Fingerprint.'),
    'client not registered' => array(array(), array('client_fingerprint' => '00:11:22:33:44:55:66:77:88:99:aa:bb:cc:dd:ee:ff'), "CACTI2RRDP ERROR: Public RSA Key Exchange - The proxy did not accept this server's RSA key."),
    'oversize reply' => array(array(), array('key_reply' => 'oversize'), 'CACTI2RRDP ERROR: Public RSA Key Exchange - The proxy reply exceeds 16384 bytes.'),
    'data after the key' => array(array(), array('key_reply' => 'trailing'), 'CACTI2RRDP ERROR: Public RSA Key Exchange - Unexpected data after the proxy key.'),
    'not a key' => array(array(), array('key_reply' => 'not-a-key'), 'CACTI2RRDP ERROR: Public RSA Key Exchange - RRDtool Proxy Server #1 did not send an RSA public key.'),
    'closed mid-key' => array(array(), array('key_reply' => 'close'), 'CACTI2RRDP ERROR: Public RSA Key Exchange - Session closed by Proxy.'),
));

test('a key pair that cannot decrypt replies is refused before connecting', function ($private) {
    if (!function_exists('socket_create')) {
        $this->markTestSkipped('The sockets extension is required.');
    }
    $root = dirname(__DIR__, 4);
    $client = rrd_proxy_interop_key();
    $private = $private === 'other' ? rrd_proxy_interop_key()['private'] : $private;
    // A listener that no connection should reach.
    $listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    socket_bind($listener, '127.0.0.1', 0);
    socket_listen($listener);
    socket_getsockname($listener, $address, $port);
    $options = array('storage_location' => 1, 'rrdp_server' => '127.0.0.1', 'rrdp_port' => $port, 'rsa_public_key' => $client['public'], 'rsa_private_key' => $private, 'rrdp_fingerprint' => $client['fingerprint']);
    $program = '$root=' . var_export($root, true) . ';$options=' . var_export($options, true) . ';' . <<<'PHP'
$config = array('rra_path' => '/fixture');
require $root . '/include/global_constants.php';
require $root . '/include/vendor/autoload.php';
$logged = array();
function cacti_log($message, ...$args) { $GLOBALS['logged'][] = $message; }
function read_config_option($key) { return $GLOBALS['options'][$key] ?? ''; }
require $root . '/lib/rrd.php';
echo json_encode(array(__rrd_proxy_init('POLLER'), $logged));
PHP;
    $result = json_decode(rrd_proxy_interop_php($this, $program, array(), true), true, 512, JSON_THROW_ON_ERROR);
    socket_set_nonblock($listener);
    $attempt = @socket_accept($listener);
    socket_close($listener);

    expect($result)->toBe(array(false, array("CACTI2RRDP ERROR: This server's RSA private key is missing or does not match its public key.")))
        ->and($attempt)->toBeFalse();
})->with(array('missing' => array(''), 'from another pair' => array('other'), 'not a key' => array('not a key')));

test('a proxy that hangs up during session setup is not used', function () {
    $client = rrd_proxy_interop_key();
    $proxy = rrd_proxy_interop_key();
    $result = rrd_proxy_interop_session($this, null, array(
        'rsa_public_key' => $client['public'], 'rsa_private_key' => $client['private'], 'rrdp_fingerprint' => $proxy['fingerprint'],
    ), array(
        'proxy_private_key' => $proxy['private'], 'proxy_public_key' => $proxy['public'], 'client_fingerprint' => $client['fingerprint'], 'hang_up_on' => 'setcnn',
    ));
    expect($result['connected'])->toBeFalse()
        ->and($result['problems'])->toContain('CACTI2RRDP ERROR: The RRDtool Proxy Server did not answer during session setup.')
        ->and($result['proxy_received'])->toBe(array('setcnn encryption off'));
});

test('a proxy that reads the request a few bytes at a time still gets all of it', function () {
    $client = rrd_proxy_interop_key();
    $proxy = rrd_proxy_interop_key();
    $result = rrd_proxy_interop_session($this, null, array(
        'rsa_public_key' => $client['public'], 'rsa_private_key' => $client['private'], 'rrdp_fingerprint' => $proxy['fingerprint'],
    ), array(
        'proxy_private_key' => $proxy['private'], 'proxy_public_key' => $proxy['public'], 'client_fingerprint' => $client['fingerprint'], 'read_chunk' => 64,
    ));
    expect($result)->toBe(array('connected' => true, 'output' => '', 'problems' => array(), 'proxy_received' => array('setcnn encryption off', 'info ./sample.rrd', 'quit')));
});

test('a short socket write is finished rather than treated as a failure', function () {
    if (!function_exists('socket_create_pair')) {
        $this->markTestSkipped('The sockets extension is required.');
    }
    $program = '$root=' . var_export(dirname(__DIR__, 4), true) . ';' . <<<'PHP'
require $root . '/include/global_constants.php';
function cacti_log(...$args) {}
require $root . '/lib/rrd.php';
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) { exit(2); }
// A blocking write returns early, with part of its data sent, once the reader
// has stalled for the send timeout. This reader takes 2 KiB at a time and
// stalls a little longer than that, so every write is cut short.
socket_set_option($sockets[0], SOL_SOCKET, SO_SNDBUF, 1024);
socket_set_option($sockets[0], SOL_SOCKET, SO_SNDTIMEO, array('sec' => 0, 'usec' => 300000));
$data = random_bytes(8192);
// The reader stops after the expected length: it inherits the writing end of
// the pair too, so it would never see end of file.
$reader = proc_open(array(PHP_BINARY, '-r', 'stream_set_read_buffer(STDIN, 0); $h = ""; while (strlen($h) < $argv[1] && ($b = fread(STDIN, 2048)) !== false && $b !== "") { $h .= $b; usleep(320000); } echo strlen($h), " ", md5($h);', (string) strlen($data)),
    array(0 => socket_export_stream($sockets[1]), 1 => array('pipe', 'w')), $pipes);
$short = @socket_write($sockets[0], $data);
$sent = rrdtool_proxy_write($sockets[0], substr($data, (int) $short));
$received = stream_get_contents($pipes[1]);
proc_close($reader);
echo json_encode(array(is_int($short) && $short > 0 && $short < strlen($data), $sent, $received === strlen($data) . ' ' . md5($data)));
PHP;
    $result = json_decode(rrd_proxy_interop_php($this, $program, array(), true), true, 512, JSON_THROW_ON_ERROR);
    // Whether a local socket cuts a write short depends on the kernel; a run
    // that could not force one has not exercised the loop.
    if ($result[0] !== true) {
        $this->markTestSkipped('This platform did not cut the first write short.');
    }
    expect($result)->toBe(array(true, true, true));
});

test('an oversize key reply is refused without reading past the cap', function () {
    if (!function_exists('socket_create_pair')) {
        $this->markTestSkipped('The sockets extension is required.');
    }
    $program = '$root=' . var_export(dirname(__DIR__, 4), true) . ';' . <<<'PHP'
require $root . '/include/global_constants.php';
$logged = array();
function cacti_log($message, ...$args) { $GLOBALS['logged'][] = $message; }
require $root . '/lib/rrd.php';
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) { exit(2); }
foreach ($sockets as $socket) {
    socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, 262144);
    socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 262144);
}
// The cap, the terminator and more, all in one burst, with no terminator inside the cap.
$burst = str_repeat('A', 16384 + 7) . "_EOT_\r\n" . str_repeat('B', 5000);
$written = 0;
while ($written < strlen($burst)) {
    $written += socket_write($sockets[1], substr($burst, $written));
}
socket_shutdown($sockets[1], 1);
$key = rrdtool_proxy_read_key($sockets[0], 'POLLER', 2);
$left = '';
while (($chunk = socket_read($sockets[0], 65536, PHP_BINARY_READ)) !== false && $chunk !== '') {
    $left .= $chunk;
}
echo json_encode(array($key, $logged, strlen($burst) - strlen($left)));
PHP;
    expect(json_decode(rrd_proxy_interop_php($this, $program, array(), true), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(array(false, array('CACTI2RRDP ERROR: Public RSA Key Exchange - The proxy reply exceeds 16384 bytes.'), 16384 + 7));
});

test('a font path the proxy would split or keep quotes in is not sent', function () {
    $client = rrd_proxy_interop_key();
    $proxy = rrd_proxy_interop_key();
    $result = rrd_proxy_interop_session($this, null, array(
        'rsa_public_key' => $client['public'], 'rsa_private_key' => $client['private'], 'rrdp_fingerprint' => $proxy['fingerprint'],
        'path_rrdtool_default_font' => "/usr/share/fonts/Deja Vu's.ttf",
    ), array('proxy_private_key' => $proxy['private'], 'proxy_public_key' => $proxy['public'], 'client_fingerprint' => $client['fingerprint']));
    expect($result)->toBe(array(
        'connected' => true,
        'output' => '',
        'problems' => array('CACTI2RRDP WARNING: The RRDtool default font path contains a blank, a quote or a backslash and was not sent to the RRDtool Proxy Server.'),
        'proxy_received' => array('setcnn encryption off', 'info ./sample.rrd', 'quit'),
    ));
});

test('a silent proxy is given up on when the key exchange times out', function () {
    if (!function_exists('socket_create_pair')) {
        $this->markTestSkipped('The sockets extension is required.');
    }
    $program = '$root=' . var_export(dirname(__DIR__, 4), true) . ';' . <<<'PHP'
require $root . '/include/global_constants.php';
$logged = array();
function cacti_log($message, ...$args) { $GLOBALS['logged'][] = $message; }
require $root . '/lib/rrd.php';
if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) { exit(2); }
socket_write($sockets[1], 'partial key');
$started = hrtime(true);
$key = rrdtool_proxy_read_key($sockets[0], 'POLLER', 0.3);
echo json_encode(array($key, $logged, (hrtime(true) - $started) / 1e9 < 2));
PHP;
    expect(json_decode(rrd_proxy_interop_php($this, $program, array(), true), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(array(false, array('CACTI2RRDP ERROR: Public RSA Key Exchange - Time-out while reading'), true));
});

/**
 * The command Kadupul's graph builder sends to the proxy for a two-source
 * graph, with $graph_data_array choosing graph, graphv or xport.
 */
function rrd_proxy_interop_built_command($test, array $graph_data_array): string
{
    require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';
    $items = array(
        rrd_characterization_item(1, 'AREA', rrd_characterization_ds('traffic_in') + array('hex' => '00CF00', 'text_format' => 'Inbound  peak')),
        rrd_characterization_item(2, 'LINE1', rrd_characterization_ds('errors') + array('hex' => 'FF0000', 'text_format' => 'Errors')),
    );
    $db = rrd_characterization_graph_db(rrd_characterization_graph(), $items);
    $paths = array(11 => '<path_rra>/router_traffic_11.rrd', 12 => '<path_rra>/errors/router_errors_12.rrd');
    foreach ($db as $index => $row) {
        if ($row['sql'] === 'SELECT name, data_source_path FROM data_template_data') {
            $db[$index]['result']['data_source_path'] = $paths[$row['params'][0]];
        }
    }
    $output = rrd_characterization_proxy_run($test, array(
        'options' => rrd_characterization_options(),
        'db' => $db,
        'calls' => array(array('fn' => 'rrdtool_function_graph', 'args' => array(7, 0, array('graph_start' => 1700000000, 'graph_end' => 1700003600) + $graph_data_array, false, array(), 0))),
    ), 3);
    $built = array_values(array_filter($output['received'], function ($command) {
        return preg_match('/^(graph|graphv|xport) /', $command) === 1;
    }));
    expect($built)->toHaveCount(1);

    return $built[0];
}

/**
 * Pass $command through rrdproxy's own checks and path resolution against an
 * RRA directory holding the two RRDs, then to `rrdtool -` in that directory,
 * as rrdproxy's lib/client.php does at 54aad57 (lines 218-254).
 */
function rrd_proxy_interop_through_rrdproxy($test, array $rrdproxy, string $binary, string $command): array
{
    $rra = sys_get_temp_dir() . '/rrd-proxy-rra-' . bin2hex(random_bytes(8));
    mkdir($rra . '/errors', 0700, true);
    $rrds = array('router_traffic_11.rrd' => array('traffic_in', 'traffic_out'), 'errors/router_errors_12.rrd' => array('errors'));
    $script = '';
    foreach ($rrds as $file => $sources) {
        $script .= 'create ' . $file . ' --start 1699990000 --step 300';
        foreach ($sources as $source) {
            $script .= ' DS:' . $source . ':GAUGE:600:U:U';
        }
        $script .= ' RRA:AVERAGE:0.5:1:100 RRA:MIN:0.5:1:100 RRA:MAX:0.5:1:100 RRA:LAST:0.5:1:100' . "\n";
        for ($time = 1699990300; $time <= 1700003600; $time += 300) {
            $script .= 'update ' . $file . ' ' . $time . str_repeat(':' . ($time % 7), count($sources)) . "\n";
        }
    }
    $environment = array('PATH' => getenv('PATH'), 'LANG' => 'C', 'LC_ALL' => 'C', 'HOME' => $rra, 'XDG_CACHE_HOME' => $rra . '/cache');
    $process = proc_open(array($binary, '-'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $rra, $environment);
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $created = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
    expect($created)->not->toContain('ERROR');

    $program = '$rrdproxy=' . var_export($rrdproxy, true) . ';$binary=' . var_export($binary, true) . ';$rra=' . var_export($rra, true)
        . ';$command=' . var_export($command, true) . ';$environment=' . var_export($environment, true) . ';' . <<<'PHP'
require $rrdproxy['autoload'];
require $rrdproxy['source'] . '/lib/functions.php';
$rrdp_config['path_rra'] = $rra;
list($cmd, $cmd_options) = explode(' ', trim($command), 2);
$unsafe = rrdp_command_has_unsafe_path($cmd_options);
$resolved = rrdp_resolve_command_paths($cmd, $cmd_options);
$stdout = null;
if (!$unsafe && $resolved !== false) {
    $process = proc_open(array($binary, '-', $rra), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, $rra, $environment);
    fwrite($pipes[0], $cmd . ' ' . $resolved . "\r\n");
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
}
echo json_encode(array('unsafe' => $unsafe, 'resolved' => $resolved === false ? false : str_replace(realpath($rra), '<RRA>', $resolved), 'stdout' => $stdout === null ? null : str_replace(realpath($rra), '<RRA>', $stdout)), JSON_INVALID_UTF8_SUBSTITUTE);
PHP;
    try {
        return json_decode(rrd_proxy_interop_php($test, $program, array(), true), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rra, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($rra);
    }
}

test('an xport built by Kadupul passes rrdproxy and returns data from RRDtool', function () {
    $rrdproxy = rrd_proxy_interop_rrdproxy();
    $binary = getenv('RRDTOOL_TEST_BINARY');
    if ($rrdproxy === null || !$binary || !is_executable($binary)) {
        $this->markTestSkipped('Set RRDPROXY_SOURCE to an rrdproxy checkout and RRDTOOL_TEST_BINARY to run it.');
    }
    $command = rrd_proxy_interop_built_command($this, array('export_csv' => true));
    expect($command)->toContain(' DEF:a=./router_traffic_11.rrd:')->toContain(' DEF:b=./errors/router_errors_12.rrd:');

    $result = rrd_proxy_interop_through_rrdproxy($this, $rrdproxy, $binary, $command);
    expect($result['unsafe'])->toBeFalse()
        ->and($result['resolved'])->toContain('DEF:a=<RRA>/router_traffic_11.rrd:')
        ->and($result['stdout'])->not->toContain('ERROR')
        ->and($result['stdout'])->toMatch('/<row><v>[0-9.e+-]+<\/v><v>[0-9.e+-]+<\/v><\/row>/')
        ->and($result['stdout'])->toMatch('/^OK u:/m')
        // rrdproxy joins its tokens with single blanks, inside quotes too.
        ->and($command)->toContain("'Inbound  peak'")
        ->and($result['stdout'])->toContain('<entry>Inbound peak</entry>');
});

// KNOWN LIMITATION of rrdproxy at 54aad57, pinned so that a fixed rrdproxy
// fails this test and the documentation in docs/migrations/graphing-rrd.md
// can be revised. Its lib/functions.php refuses any command with a blank,
// '=', ':' or ',' before '/' or '\' (line 412), as in the COMMENT:"  \n"
// Kadupul adds after the date range of any graph with a start and end. Past
// that, it takes the first bare token after `graph -` as the output file and
// rewrites it (lines 442 and 469): here a word of the title, or the first
// graph element when nothing comes before it.
test('KNOWN LIMITATION: rrdproxy refuses a graph built by Kadupul and would misread it', function (array $graph_data_array) {
    $rrdproxy = rrd_proxy_interop_rrdproxy();
    $binary = getenv('RRDTOOL_TEST_BINARY');
    if ($rrdproxy === null || !$binary || !is_executable($binary)) {
        $this->markTestSkipped('Set RRDPROXY_SOURCE to an rrdproxy checkout and RRDTOOL_TEST_BINARY to run it.');
    }
    $command = rrd_proxy_interop_built_command($this, $graph_data_array);
    expect($command)->toContain(' DEF:a=./router_traffic_11.rrd:');

    $result = rrd_proxy_interop_through_rrdproxy($this, $rrdproxy, $binary, $command);
    expect($result['unsafe'])->toBeTrue()
        ->and($command)->toContain(' COMMENT:"  \\n"')
        ->and($command)->toContain(" --title='Traffic &amp; ")
        ->and($result['resolved'])->toContain(" --title='Traffic <RRA>/&amp; ");
})->with(array('graph' => array(array()), 'graphv' => array(array('graphv' => true))));
