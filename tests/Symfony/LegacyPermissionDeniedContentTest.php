<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\BrowserSession;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\LegacyBrowserWorker;
use Kadupul\Platform\Infrastructure\Legacy\LegacyBrowserWorkerRunner;
use Kadupul\Platform\Infrastructure\Legacy\LegacyPermissionDeniedContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** The real worker launcher and result checks against a stand-in bin/ script. */
final class LegacyPermissionDeniedContentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/permission-denied-worker-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/include', 0700, true);
        mkdir($this->root . '/bin', 0700);
        file_put_contents($this->root . '/include/config.php', '<?php $url_path = "/kadupul/";');
        file_put_contents($this->root . '/include/cacti_version', '1.3.0');
        // Records the handoff it was given and answers with the prepared result.
        file_put_contents($this->root . '/bin/legacy-permission-denied.php', <<<'PHP'
            <?php
            $root = dirname(__DIR__);
            file_put_contents($root . '/handoff.json', stream_get_contents(STDIN));
            echo file_get_contents($root . '/result.json');
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (['include/config.php', 'include/cacti_version', 'bin/legacy-permission-denied.php', 'handoff.json', 'result.json'] as $file) {
            if (is_file($this->root . '/' . $file)) {
                unlink($this->root . '/' . $file);
            }
        }
        rmdir($this->root . '/include');
        rmdir($this->root . '/bin');
        rmdir($this->root);
    }

    /** @param array<string, mixed> $result */
    private function content(array $result): LegacyPermissionDeniedContent
    {
        file_put_contents($this->root . '/result.json', json_encode($result, JSON_THROW_ON_ERROR));
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE version (cacti TEXT); INSERT INTO version VALUES ('1.3.0')");
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $pdo->prepare("INSERT INTO settings VALUES ('path_php_binary', ?)")->execute([PHP_BINARY]);
        $database = self::createStub(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $session = self::createStub(BrowserSession::class);
        $session->method('read')->willReturn(['sess_user_id' => 7, 'sess_user_credential' => str_repeat('a', 64)]);
        $requests = new RequestStack();
        $requests->push(Request::create('https://example.test/kadupul/permission_denied.php', 'GET', [], [], [], [
            'HTTP_REFERER' => 'https://example.test/kadupul/host.php', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]));

        return new LegacyPermissionDeniedContent(new LegacyBrowserWorker(new LegacyBrowserWorkerRunner($this->root, $requests, $session, $database, new InstallationConfiguration($this->root), new NullLogger()), $requests, new InstallationConfiguration($this->root)));
    }

    /** @return array<string, mixed> */
    private static function workerResult(array $overrides = []): array
    {
        return $overrides + [
            'status' => 'ok', 'actor' => 7, 'replacement' => null, 'ajax' => false, 'return_to' => '/kadupul/host.php',
            'version' => '1.3.0', 'copyright' => '(c) 2004-2026 - The Cacti Group',
            'policies' => ['Content-Security-Policy' => "default-src 'self'"],
        ];
    }

    public function testWorkerReceivesTheNativePagePathAndRequestContext(): void
    {
        $denied = $this->content(self::workerResult(['replacement' => '<p>custom</p>', 'ajax' => true]))->forActor(new Actor(7, 'viewer'));
        self::assertSame('/kadupul/host.php', $denied->returnTo);
        self::assertTrue($denied->ajax);
        self::assertSame('<p>custom</p>', $denied->replacement);
        self::assertSame(['Content-Security-Policy' => "default-src 'self'"], $denied->policies);
        $handoff = json_decode((string) file_get_contents($this->root . '/handoff.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('/kadupul/permission_denied.php', $handoff['page_path']);
        self::assertArrayNotHasKey('index_path', $handoff);
        self::assertSame(7, $handoff['actor']);
        self::assertSame('https://example.test/kadupul/host.php', $handoff['server']['HTTP_REFERER']);
        self::assertSame('XMLHttpRequest', $handoff['server']['HTTP_X_REQUESTED_WITH']);
    }

    public function testAbsentRefererStaysAbsent(): void
    {
        self::assertNull($this->content(self::workerResult(['return_to' => null]))->forActor(new Actor(7, 'viewer'))->returnTo);
    }

    public static function malformed(): iterable
    {
        yield 'protocol relative return' => [['return_to' => '//foreign.invalid/']];
        yield 'scheme return' => [['return_to' => 'javascript:alert(1)']];
        yield 'absolute return' => [['return_to' => 'https://foreign.invalid/']];
        yield 'control character return' => [['return_to' => "/kadupul/\nLocation: x"]];
        yield 'empty return' => [['return_to' => '']];
        yield 'array return' => [['return_to' => ['/kadupul/']]];
        yield 'replacement type' => [['replacement' => ['<p>']]];
        yield 'ajax type' => [['ajax' => 1]];
        yield 'version type' => [['version' => null]];
        yield 'missing policy' => [['policies' => []]];
        yield 'foreign header' => [['policies' => ['Set-Cookie' => 'a=b']]];
        yield 'policy injection' => [['policies' => ['Content-Security-Policy' => "default-src 'self'\r\nX: y"]]];
        yield 'other actor' => [['actor' => 8]];
        yield 'failed worker' => [['status' => 'failed']];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('malformed')]
    public function testMalformedWorkerResultsAreRefused(array $override): void
    {
        $this->expectException(\RuntimeException::class);
        $this->content(self::workerResult($override))->forActor(new Actor(7, 'viewer'));
    }
}
