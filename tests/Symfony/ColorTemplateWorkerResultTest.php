<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\ColorTemplates\Infrastructure\Legacy\LegacyColorTemplateSynchronizer;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorTemplateWorkerResultTest extends TestCase
{
    public static function unconfirmedResults(): iterable
    {
        $success = ['actor' => 42, 'template_id' => 7, 'status' => 'ok', 'summary' => ['template' => 'Palette', 'aggregate_templates' => 1, 'aggregate_graphs' => 2], 'diagnostic' => null];
        $record = 'KADUPUL_COLOR_SYNC_RESULT=' . json_encode($success, JSON_THROW_ON_ERROR);
        yield 'two success markers' => [$record . "\n" . $record];
        yield 'valid then malformed reserved marker' => [$record . "\nKADUPUL_COLOR_SYNC_RESULT=not-json"];
        yield 'wrong actor' => ['KADUPUL_COLOR_SYNC_RESULT=' . json_encode(array_replace($success, ['actor' => 43]))];
        yield 'wrong template' => ['KADUPUL_COLOR_SYNC_RESULT=' . json_encode(array_replace($success, ['template_id' => 8]))];
        yield 'string template identity' => ['KADUPUL_COLOR_SYNC_RESULT=' . json_encode(array_replace($success, ['template_id' => '7']))];
        yield 'unexpected output' => ['unexpected output' . "\n" . $record];
        yield 'missing summary fields' => ['KADUPUL_COLOR_SYNC_RESULT=' . json_encode(array_replace($success, ['summary' => []]))];
        yield 'numeric-key summary object' => ['KADUPUL_COLOR_SYNC_RESULT=' . json_encode(array_replace($success, ['summary' => (object) ['0' => 7]]))];
        yield 'string count' => ['KADUPUL_COLOR_SYNC_RESULT=' . json_encode(array_replace($success, ['summary' => ['template' => 'Palette', 'aggregate_templates' => '1', 'aggregate_graphs' => 2]]))];
        yield 'negative count' => ['KADUPUL_COLOR_SYNC_RESULT=' . json_encode(array_replace($success, ['summary' => ['template' => 'Palette', 'aggregate_templates' => -1, 'aggregate_graphs' => 2]]))];
        yield 'malformed JSON' => ['KADUPUL_COLOR_SYNC_RESULT={"status":oops}'];
    }

    #[DataProvider('unconfirmedResults')]
    public function testUnconfirmedWorkerSuccessNeverProducesASuccessAudit(string $output): void
    {
        $this->withWorker($output, function (LegacyColorTemplateSynchronizer $worker, AuditTrail $audit): void {
            $audit->expects(self::once())->method('record')->with(self::callback(
                static fn(AuditEvent $event): bool => $event->outcome === AuditEvent::FAILED
            ));
            $this->expectException(\RuntimeException::class);
            $worker->sync(42, 7);
        });
    }

    public function testOneConfirmedWorkerResultReturnsTypedSummaryAndAuditsSuccess(): void
    {
        $summary = ['template' => 'Palette', 'aggregate_templates' => 1, 'aggregate_graphs' => 2];
        $output = 'KADUPUL_COLOR_SYNC_RESULT=' . json_encode(['actor' => 42, 'template_id' => 7, 'status' => 'ok', 'summary' => $summary, 'diagnostic' => null]);
        $this->withWorker($output, function (LegacyColorTemplateSynchronizer $worker, AuditTrail $audit) use ($summary): void {
            $audit->expects(self::once())->method('record')->with(self::callback(
                static fn(AuditEvent $event): bool => $event->outcome === AuditEvent::SUCCEEDED
            ));
            self::assertSame($summary, $worker->sync(42, 7));
        });
    }

    public static function deniedResults(): iterable
    {
        yield 'denied' => ['denied', \Kadupul\ColorTemplates\Application\Query\ColorTemplateAccessDenied::class];
        yield 'invalid' => ['invalid', \InvalidArgumentException::class];
    }

    #[DataProvider('deniedResults')]
    public function testVerifiedWorkerRejectionRetainsItsDeniedOutcome(string $status, string $exception): void
    {
        $output = 'KADUPUL_COLOR_SYNC_RESULT=' . json_encode(['actor' => 42, 'template_id' => 7, 'status' => $status, 'summary' => [], 'diagnostic' => null]);
        $this->withWorker($output, function (LegacyColorTemplateSynchronizer $worker, AuditTrail $audit) use ($exception): void {
            $audit->expects(self::once())->method('record')->with(self::callback(
                static fn(AuditEvent $event): bool => $event->outcome === AuditEvent::DENIED
            ));
            $this->expectException($exception);
            $worker->sync(42, 7);
        }, 1);
    }

    public function testFailedWorkerConfigurationReadIsAuditedBeforeStartingAWorker(): void
    {
        $db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]);
        $db->exec("CREATE VIEW settings AS SELECT 'path_php_binary' AS name,json_extract('invalid-json', '$') AS value");
        self::assertFalse($db->query("SELECT value FROM settings WHERE name='path_php_binary'"));
        self::assertNotSame('00000', $db->errorCode());
        $connection = $this->createMock(DatabaseConnection::class);
        $connection->method('get')->willReturn($db);
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::once())->method('record')->with(self::callback(static fn(AuditEvent $event): bool => $event->outcome === AuditEvent::FAILED));
        $worker = new LegacyColorTemplateSynchronizer($connection, $audit, '/does-not-exist-color-worker');
        $this->expectExceptionMessage('worker configuration could not be verified');
        $worker->sync(42, 7);
    }

    private function withWorker(string $output, callable $assert, int $exitCode = 0): void
    {
        $directory = sys_get_temp_dir() . '/color-worker-result-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        $path = $directory . '/bin/legacy-color-template-sync.php';
        file_put_contents($path, '<?php $input = json_decode(stream_get_contents(STDIN), true);'
            . ' if ($input !== ["actor" => 42, "template_id" => 7]) { exit(9); }'
            . ' echo ' . var_export($output . "\n", true) . '; exit(' . $exitCode . ');');
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_php_binary', PHP_BINARY]);
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $audit = $this->createMock(AuditTrail::class);
            $assert(new LegacyColorTemplateSynchronizer($database, $audit, $directory), $audit);
        } finally {
            unlink($path);
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }
}
