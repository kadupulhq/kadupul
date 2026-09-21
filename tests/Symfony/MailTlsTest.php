<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MailTlsTest extends TestCase
{
    public static function modes(): iterable
    {
        foreach (['tls', 'ssl'] as $mode) {
            foreach (['trusted', 'untrusted', 'wrong-host'] as $certificate) {
                yield $mode . ' ' . $certificate => [$mode, $certificate];
            }
        }
    }

    #[DataProvider('modes')]
    public function testEncryptedDeliveryAndCertificateVerification(string $mode, string $certificate): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-mail-tls-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $server = null;
        $pid = null;
        try {
            $this->certificate($directory, 'server', $certificate === 'wrong-host' ? '192.0.2.1' : '127.0.0.1');
            $this->certificate($directory, 'other', '127.0.0.1');
            $context = stream_context_create(['ssl' => [
                'local_cert' => $directory . '/server.crt', 'local_pk' => $directory . '/server.key',
                'verify_peer' => false, // Server does not request client certificates.
            ]]);
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
            self::assertIsResource($server);
            $port = substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                $this->serve($server, $directory . '/transcript', $mode);
                exit(0);
            }
            fclose($server);
            $server = null;
            // cafile is PHP_INI_PERDIR: use a child runtime, never alter machine trust.
            $client = new Process([
                PHP_BINARY, '-d', 'openssl.cafile=' . $directory . ($certificate === 'untrusted' ? '/other.crt' : '/server.crt'),
                '-d', 'openssl.capath=', __DIR__ . '/mail_tls_client.php',
            ], dirname(__DIR__, 2));
            $client->setInput(json_encode([
                'settings_how' => '2', 'settings_from_email' => 'sender@example.test',
                'settings_test_email' => 'recipient@example.test', 'settings_smtp_host' => '127.0.0.1',
                'settings_smtp_port' => $port, 'settings_smtp_timeout' => '3', 'settings_smtp_secure' => $mode,
                'settings_smtp_username' => 'tls-user', 'settings_smtp_password' => 'tls-fixture-secret',
            ], JSON_THROW_ON_ERROR));
            $client->setTimeout(15);
            $status = $client->run();
            pcntl_waitpid($pid, $childStatus);
            self::assertTrue(pcntl_wifexited($childStatus));
            self::assertSame(0, pcntl_wexitstatus($childStatus));
            $transcript = file_get_contents($directory . '/transcript');
            $output = $client->getOutput() . $client->getErrorOutput();
            self::assertSame($certificate === 'trusted' ? 0 : 1, $status, $output);
            self::assertStringNotContainsString('tls-fixture-secret', $output);
            self::assertSame($mode === 'tls' ? 1 : 0, substr_count($transcript, "STARTTLS\r\n"));
            if ($certificate === 'trusted') {
                self::assertStringContainsString('SMTP server accepted', $output);
                self::assertStringContainsString("TLS established\n", $transcript);
                self::assertStringContainsString('AUTH PLAIN ', $transcript);
                self::assertStringContainsString('Subject: Kadupul test email', $transcript);
                self::assertSame(1, substr_count($transcript, 'MAIL FROM:'));
                self::assertSame($mode === 'tls' ? 2 : 1, substr_count($transcript, 'EHLO '));
                self::assertLessThan(strpos($transcript, 'AUTH PLAIN '), strpos($transcript, "TLS established\n"));
            } else {
                self::assertStringContainsString('could not be confirmed', $output);
                self::assertStringNotContainsString('AUTH PLAIN ', $transcript);
                self::assertStringNotContainsString('MAIL FROM:', $transcript);
            }
        } finally {
            if (is_resource($server)) {
                fclose($server);
            }
            if ($pid !== null && $pid > 0 && pcntl_waitpid($pid, $unused, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $unused);
            }
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function certificate(string $directory, string $name, string $ip): void
    {
        $config = $directory . '/' . $name . '.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\nx509_extensions=extensions\n[dn]\n[extensions]\nsubjectAltName=IP:$ip\nbasicConstraints=critical,CA:TRUE\n");
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048];
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => $ip], $key, $options);
        $cert = openssl_csr_sign($csr, null, $key, 1, $options);
        self::assertTrue(openssl_x509_export_to_file($cert, $directory . '/' . $name . '.crt'));
        self::assertTrue(openssl_pkey_export_to_file($key, $directory . '/' . $name . '.key', null, $options));
    }

    private function serve($server, string $capture, string $mode): void
    {
        $connection = stream_socket_accept($server, 10);
        if ($connection === false) {
            exit(2);
        }
        stream_set_timeout($connection, 5);
        $transcript = '';
        $encrypted = false;
        if ($mode === 'ssl') {
            $encrypted = @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
            if ($encrypted) {
                $transcript .= "TLS established\n";
            }
        }
        if ($mode === 'tls' || $encrypted) {
            fwrite($connection, "220 localhost TLS fixture\r\n");
            $data = false;
            while (($line = fgets($connection)) !== false) {
                $transcript .= $line;
                if ($data) {
                    if ($line === ".\r\n") {
                        $data = false;
                        fwrite($connection, "250 queued\r\n");
                    }
                } elseif ($line === "STARTTLS\r\n") {
                    fwrite($connection, "220 start TLS\r\n");
                    if (@stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                        break;
                    }
                    $encrypted = true;
                    $transcript .= "TLS established\n";
                } elseif (str_starts_with($line, 'EHLO ')) {
                    fwrite($connection, $encrypted ? "250-localhost\r\n250 AUTH PLAIN\r\n" : "250-localhost\r\n250 STARTTLS\r\n");
                } elseif (str_starts_with($line, 'AUTH PLAIN ')) {
                    $expected = 'AUTH PLAIN ' . base64_encode("tls-user\0tls-user\0tls-fixture-secret") . "\r\n";
                    fwrite($connection, $encrypted && $line === $expected ? "235 authenticated\r\n" : "535 rejected\r\n");
                } elseif ($line === "DATA\r\n") {
                    $data = true;
                    fwrite($connection, "354 send message\r\n");
                } elseif ($line === "QUIT\r\n") {
                    fwrite($connection, "221 goodbye\r\n");
                    break;
                } else {
                    fwrite($connection, "250 ok\r\n");
                }
            }
        }
        file_put_contents($capture, $transcript);
        fclose($connection);
        fclose($server);
    }
}
