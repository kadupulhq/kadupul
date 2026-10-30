<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\BrowserSession;
use Kadupul\Platform\Contract\BrowserWorkerMode;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Platform\Infrastructure\Legacy\LegacyBrowserWorkerRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class BrowserWorkerRunnerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/browser-runner-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/include', 0700, true);
        mkdir($this->directory . '/bin', 0700);
        file_put_contents($this->directory . '/include/config.php', '<?php $url_path = "/nested/"; $session_name = "Cacti";');
        file_put_contents($this->directory . '/include/cacti_version', '1.3.0');
        file_put_contents($this->directory . '/bin/legacy-probe.php', <<<'WORKER'
<?php
$raw = stream_get_contents(STDIN);
$command = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
echo json_encode(['status' => 'ok', 'actor' => $command['actor'], 'bytes' => strlen($raw), 'arguments' => count($argv), 'query' => $command['query']]);
WORKER);
    }

    protected function tearDown(): void
    {
        foreach (['/bin/*', '/include/*'] as $pattern) {
            foreach (glob($this->directory . $pattern) ?: [] as $file) {
                unlink($file);
            }
        }
        rmdir($this->directory . '/bin');
        rmdir($this->directory . '/include');
        rmdir($this->directory);
    }

    /** @return iterable<string, array{string, BrowserWorkerMode, bool}> */
    public static function methods(): iterable
    {
        yield 'GET read' => ['GET', BrowserWorkerMode::Read, true];
        yield 'HEAD read' => ['HEAD', BrowserWorkerMode::Read, true];
        yield 'POST read refused' => ['POST', BrowserWorkerMode::Read, false];
        yield 'GET mutation refused' => ['GET', BrowserWorkerMode::Mutation, false];
        yield 'HEAD mutation refused' => ['HEAD', BrowserWorkerMode::Mutation, false];
        yield 'PUT mutation refused' => ['PUT', BrowserWorkerMode::Mutation, false];
        yield 'POST mutation' => ['POST', BrowserWorkerMode::Mutation, true];
    }

    #[DataProvider('methods')]
    public function testMethodAdmissionUsesTheActualRequest(string $method, BrowserWorkerMode $mode, bool $admitted): void
    {
        if (!$admitted) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('admitted native session');
        }
        $result = $this->runner($method)->run('legacy-probe.php', ['page_path' => '/nested/managers.php'], 7, mode: $mode);
        self::assertSame(7, $result['actor']);
        self::assertSame(1, $result['arguments'], 'Credentials and command must stay off argv.');
    }

    public function testMutationAcceptsEscapedTextAboveTheReadLimit(): void
    {
        $result = $this->runner('POST')->run('legacy-probe.php', ['notes' => str_repeat("\x01", 65535)], 7, forwardRequest: false, mode: BrowserWorkerMode::Mutation);
        self::assertGreaterThan(BrowserWorkerMode::Read->inputLimit(), $result['bytes']);
        self::assertSame([], $result['query']);
    }

    public function testReadRetainsItsOriginalInputLimit(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $this->runner('GET')->run('legacy-probe.php', ['notes' => str_repeat('a', 65536)], 7);
    }

    public function testMutationHasABoundedInputLimit(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $this->runner('POST')->run('legacy-probe.php', ['notes' => str_repeat('a', 1048576)], 7, mode: BrowserWorkerMode::Mutation);
    }

    public function testAnEntryCannotReplaceTheAdmittedActor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runner('POST')->run('legacy-probe.php', ['actor' => 9], 7, mode: BrowserWorkerMode::Mutation);
    }

    public function testAnEntryCannotSelectAWorkerOutsideTheOwnedBinDirectory(): void
    {
        symlink($this->directory . '/include/config.php', $this->directory . '/bin/legacy-outside.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('worker is unavailable');
        $this->runner('POST')->run('legacy-outside.php', [], 7, mode: BrowserWorkerMode::Mutation);
    }

    private function runner(string $method): LegacyBrowserWorkerRunner
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec("CREATE TABLE version (cacti TEXT); INSERT INTO version VALUES ('1.3.0'); CREATE TABLE settings (name TEXT, value TEXT)");
        $statement = $pdo->prepare('INSERT INTO settings VALUES (?, ?)');
        $statement->execute(['path_php_binary', PHP_BINARY]);
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $session = $this->createMock(BrowserSession::class);
        $session->method('read')->willReturn(['sess_user_id' => 7, 'sess_user_credential' => str_repeat('a', 64)]);
        $requests = new RequestStack();
        $requests->push(Request::create('/nested/managers.php?client=ignored', $method));

        return new LegacyBrowserWorkerRunner($this->directory, $requests, $session, $database, new InstallationConfiguration($this->directory), new NullLogger());
    }
}
