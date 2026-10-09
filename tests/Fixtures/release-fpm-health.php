<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// A real FastCGI request from inside an isolated release container.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$socket = stream_socket_client('tcp://127.0.0.1:9000', $error, $message, 5);
if ($socket === false) {
    throw new RuntimeException('FPM socket unavailable');
}
stream_set_timeout($socket, 5);

$write = static function (int $type, string $body) use ($socket): void {
    $bytes = pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0) . $body;
    while ($bytes !== '') {
        $written = fwrite($socket, $bytes);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Incomplete FastCGI request');
        }
        $bytes = substr($bytes, $written);
    }
};
$read = static function (int $length) use ($socket): string {
    $bytes = '';
    while (strlen($bytes) < $length) {
        $part = fread($socket, $length - strlen($bytes));
        if ($part === false || $part === '') {
            throw new RuntimeException('Incomplete FastCGI response');
        }
        $bytes .= $part;
    }
    return $bytes;
};

$write(1, pack('nCxxxxx', 1, 0));
$parameters = '';
foreach ([
    'SCRIPT_FILENAME' => '/var/www/html/public/index.php',
    'SCRIPT_NAME' => '/index.php',
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/healthz',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'SERVER_NAME' => 'release.invalid',
    'HTTP_HOST' => 'release.invalid',
    'SERVER_PORT' => '9000',
    'REMOTE_ADDR' => '127.0.0.1',
    'GATEWAY_INTERFACE' => 'CGI/1.1',
] as $name => $value) {
    $parameters .= chr(strlen($name)) . chr(strlen($value)) . $name . $value;
}
$write(4, $parameters);
$write(4, '');
$write(5, '');
$output = '';
for ($records = 0; $records < 32; ++$records) {
    $header = unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved', $read(8));
    $body = $read($header['length']);
    $read($header['padding']);
    if ($header['version'] !== 1 || $header['request'] !== 1) {
        throw new RuntimeException('Unexpected FastCGI response identity');
    }
    if ($header['type'] === 6) {
        $output .= $body;
    } elseif ($header['type'] === 7 && $body !== '') {
        throw new RuntimeException('FPM reported a request error');
    } elseif ($header['type'] === 3) {
        fclose($socket);
        if (strlen($body) !== 8 || unpack('Nstatus/Cprotocol', $body)['status'] !== 0 || ord($body[4]) !== 0 ||
            (explode("\r\n\r\n", $output, 2)[1] ?? '') !== '{"status":"ok"}' ||
            preg_match('/^Status: (?!200\b)/mi', $output) === 1) {
            throw new RuntimeException('FPM did not serve the real health endpoint successfully');
        }
        echo "Real FastCGI health request passed.\n";
        exit;
    }
}
throw new RuntimeException('FastCGI response exceeded the bounded record inventory');
