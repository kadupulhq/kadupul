<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Alerting\Application\Command\SendTestMail;
use Kadupul\Alerting\Application\MailDeliveryFailed;
use Kadupul\Alerting\Infrastructure\Mail\InstallationTestMailDelivery;
use Kadupul\Alerting\Infrastructure\Mail\SmtpMessageSender;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

final class TestMailTest extends TestCase
{
    private function database(array $overrides = []): DatabaseConnection
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');
        $insert = $pdo->prepare('INSERT INTO settings VALUES (?, ?)');
        foreach (array_replace([
            'settings_how' => '2', 'settings_from_email' => 'sender@example.test',
            'settings_from_name' => 'Kadupul diagnostic', 'settings_test_email' => 'recipient@example.test',
            'settings_smtp_host' => '127.0.0.1', 'settings_smtp_port' => '25',
            'settings_smtp_secure' => 'none', 'settings_smtp_timeout' => '2',
        ], $overrides) as $name => $value) {
            $insert->execute([$name, $value]);
        }

        return new class ($pdo) implements DatabaseConnection {
            public function __construct(private readonly \PDO $pdo) {}

            public function get(): \PDO
            {
                return $this->pdo;
            }
        };
    }

    public static function invalidSettings(): iterable
    {
        yield 'native mail' => [['settings_how' => '0']];
        yield 'sendmail' => [['settings_how' => '1']];
        yield 'host list' => [['settings_smtp_host' => 'localhost;backup']];
        yield 'DSN' => [['settings_smtp_host' => 'smtp://localhost']];
        yield 'port zero' => [['settings_smtp_port' => '0']];
        yield 'port overflow' => [['settings_smtp_port' => '65536']];
        yield 'timeout' => [['settings_smtp_timeout' => '301']];
        yield 'security' => [['settings_smtp_secure' => 'starttls']];
        yield 'missing recipient' => [['settings_test_email' => '']];
        yield 'recipient list' => [['settings_test_email' => 'a@example.test,b@example.test']];
        yield 'sender header injection' => [['settings_from_email' => "a@example.test\r\nBcc: other@example.test"]];
        yield 'name header injection' => [['settings_from_name' => "name\nBcc: other@example.test"]];
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsAreRejectedBeforeSmtp(array $settings): void
    {
        $this->expectException(MailDeliveryFailed::class);
        (new SendTestMail(new InstallationTestMailDelivery($this->database($settings), new SmtpMessageSender())))();
    }

    public static function databaseFailures(): iterable
    {
        yield 'exception' => [new \RuntimeException('sensitive-database-credential')];
        yield 'PHP error' => [new \Error('sensitive-installation-credential')];
    }

    #[DataProvider('databaseFailures')]
    public function testDatabaseErrorsDoNotLeakThroughVerboseConsole(\Throwable $failure): void
    {
        $database = new class ($failure) implements DatabaseConnection {
            public function __construct(private readonly \Throwable $failure) {}

            public function get(): \PDO
            {
                throw $this->failure;
            }
        };
        [$status, $display] = $this->console($database);
        self::assertSame(1, $status);
        self::assertStringContainsString('could not be confirmed', $display);
        self::assertStringNotContainsString('sensitive', $display);
        try {
            (new InstallationTestMailDelivery($database, new SmtpMessageSender()))->send('Test', 'Test');
            self::fail('Expected sanitized failure.');
        } catch (MailDeliveryFailed $error) {
            self::assertNull($error->getPrevious());
        }
    }

    public static function smtpScenarios(): iterable
    {
        yield 'accepted' => ['accept', [], 0, true];
        yield 'administrator accepted' => ['accept', [], 0, true, true];
        yield 'administrator rejected without fallback' => ['reject', [], 1, true, true];
        yield 'administrator lost acknowledgement without fallback' => ['lost', [], 1, true, true];
        yield 'administrator TLS downgrade refused without fallback' => ['accept', ['settings_smtp_secure' => 'tls'], 1, false, true];
        yield 'authenticated' => ['auth', ['settings_smtp_username' => 'test-user', 'settings_smtp_password' => 'test-secret'], 0, true];
        yield 'none disables automatic STARTTLS' => ['advertise-tls', [], 0, true];
        yield 'connection lost after data' => ['lost', [], 1, true];
        yield 'rejected after data' => ['reject', [], 1, true];
        yield 'required STARTTLS unavailable' => ['accept', ['settings_smtp_secure' => 'tls'], 1, false];
        yield 'HELO cannot downgrade required TLS' => ['helo', ['settings_smtp_secure' => 'tls'], 1, false];
        yield 'configured authentication unavailable' => ['accept', ['settings_smtp_username' => 'test-user', 'settings_smtp_password' => 'test-secret'], 1, false];
    }

    #[DataProvider('smtpScenarios')]
    public function testConsoleAgainstLoopbackSmtp(string $scenario, array $settings, int $expected, bool $sends, bool $administrator = false): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server);
        $port = substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        $capture = tempnam(sys_get_temp_dir(), 'kadupul-smtp-');
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            $this->serve($server, $capture, $scenario);
            exit(0);
        }
        fclose($server);
        try {
            $configured = array_replace($settings, ['settings_smtp_port' => $port]);
            if ($administrator) {
                $result = AdministratorNotificationTest::bridge($configured);
                self::assertSame([], $result['legacy'], 'Never fall back after an SMTP attempt.');
                self::assertSame([[7]], $result['recipients']);
                $status = count(array_filter($result['logs'], static fn(array $log): bool => str_starts_with($log[0], 'WARNING:'))) > 0 ? 1 : 0;
                if ($expected === 0) {
                    self::assertSame([['INFO: Administrative Email accepted by SMTP server.', false, 'MAILER']], $result['logs']);
                }
                $display = json_encode($result['logs']);
            } else {
                [$status, $display] = $this->console($this->database($configured));
            }
            pcntl_waitpid($pid, $childStatus);
            self::assertTrue(pcntl_wifexited($childStatus));
            self::assertSame(0, pcntl_wexitstatus($childStatus));
            self::assertSame($expected, $status, $display);
            $transcript = file_get_contents($capture);
            self::assertSame($sends ? 1 : 0, substr_count($transcript, 'MAIL FROM:'));
            self::assertSame($sends ? 1 : 0, substr_count($transcript, "DATA\r\n"));
            self::assertStringNotContainsString('test-secret', $display);
            self::assertStringNotContainsString('private-server-diagnostic', $display);
            if ($scenario === 'auth') {
                self::assertStringContainsString('AUTH PLAIN ' . base64_encode("test-user\0test-user\0test-secret"), $transcript);
            }
            if ($scenario === 'advertise-tls') {
                self::assertStringNotContainsString("STARTTLS\r\n", $transcript);
            }
            if ($sends) {
                self::assertStringContainsString($administrator ? 'RCPT TO:<admin@example.test>' : 'RCPT TO:<recipient@example.test>', $transcript);
                self::assertStringContainsString('From: Kadupul diagnostic <sender@example.test>', $transcript);
                self::assertStringContainsString($administrator ? 'Subject: Administrative warning' : 'Subject: Kadupul test email', $transcript);
                self::assertStringContainsString($administrator ? '<strong>Storage needs attention.</strong>' : 'This test email was sent by Kadupul using Symfony Mailer.', $transcript);
                if ($administrator) {
                    self::assertStringContainsString('Content-Type: text/html', $transcript);
                    self::assertStringContainsString('Content-Type: text/plain', $transcript);
                }
            }
            if ($expected === 0) {
                if (!$administrator) {
                    self::assertStringContainsString('SMTP server accepted', $display);
                }
            } else {
                self::assertStringContainsString('before retrying', $display);
                self::assertStringNotContainsString('SMTP server accepted', $display);
            }
        } finally {
            // Safe even after waitpid: only signal a child that is still ours and running.
            if (pcntl_waitpid($pid, $unused, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $unused);
            }
            unlink($capture);
        }
    }

    private function console(DatabaseConnection $database): array
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $kernel->getContainer()->get('test.service_container')->set(DatabaseConnection::class, $database);
            $application = new Application($kernel);
            $application->setAutoExit(false);
            $tester = new ApplicationTester($application);
            $status = $tester->run(['command' => 'kadupul:mail:test', '-vvv' => true]);

            return [$status, $tester->getDisplay()];
        } finally {
            $kernel->shutdown();
        }
    }

    private function serve($server, string $capture, string $scenario): void
    {
        $connection = stream_socket_accept($server, 10);
        if ($connection === false) {
            exit(2);
        }
        stream_set_timeout($connection, 5);
        fwrite($connection, "220 localhost test SMTP\r\n");
        $transcript = '';
        $data = false;
        while (($line = fgets($connection)) !== false) {
            $transcript .= $line;
            if ($data) {
                if ($line === ".\r\n") {
                    $data = false;
                    if ($scenario === 'lost') {
                        break;
                    }
                    fwrite($connection, $scenario === 'reject' ? "550 private-server-diagnostic\r\n" : "250 queued\r\n");
                }
                continue;
            }
            if (str_starts_with($line, 'EHLO')) {
                fwrite($connection, match ($scenario) {
                    'helo' => "500 ESMTP unavailable\r\n",
                    'auth' => "250-localhost\r\n250 AUTH PLAIN\r\n",
                    'advertise-tls' => "250-localhost\r\n250 STARTTLS\r\n",
                    default => "250 localhost\r\n",
                });
            } elseif (str_starts_with($line, 'AUTH PLAIN ')) {
                $expected = 'AUTH PLAIN ' . base64_encode("test-user\0test-user\0test-secret") . "\r\n";
                fwrite($connection, $line === $expected ? "235 authenticated\r\n" : "535 authentication failed\r\n");
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
        file_put_contents($capture, $transcript);
        fclose($connection);
        fclose($server);
    }
}
