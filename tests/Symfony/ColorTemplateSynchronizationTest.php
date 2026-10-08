<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Query\ColorTemplateAccessDenied;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyColorTemplateStore;
use Kadupul\Graphing\Infrastructure\Legacy\LegacyColorTemplateSynchronization;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorTemplateSynchronizationTest extends TestCase
{
    private string $directory;
    /** @var list<AuditEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kadupul-color-sync-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/bin', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/bin/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory . '/bin');
        rmdir($this->directory);
    }

    public function testTheWorkerReceivesTheConfirmedRevisionAndItsSummaryIsReturned(): void
    {
        $this->worker('file_put_contents(__DIR__ . "/command", stream_get_contents(STDIN)); print "legacy notice\n"; print "KADUPUL_COLOR_SYNC_RESULT=" . json_encode(["status" => "ok", "summary" => ["template" => "Reds", "aggregate_templates" => 2, "aggregate_graphs" => 1]]) . "\n";');
        self::assertSame(['template' => 'Reds', 'aggregate_templates' => 2, 'aggregate_graphs' => 1], $this->adapter()->synchronize(42, 7, 'revision'));
        self::assertSame(['actor' => 42, 'template_id' => 7, 'revision' => 'revision'], json_decode((string) file_get_contents($this->directory . '/bin/command'), true));
        self::assertSame([[AuditEvent::ALLOWED, AuditEvent::SUCCEEDED, '7']], array_map(static fn(AuditEvent $event): array => [$event->decision, $event->outcome, $event->targetId], $this->events));
    }

    public function testDeniedAndChangedResultsKeepTheirMeaning(): void
    {
        $this->worker('print "KADUPUL_COLOR_SYNC_RESULT=" . json_encode(["status" => "denied", "summary" => null]) . "\n"; exit(1);');
        try {
            $this->adapter()->synchronize(42, 7, 'revision');
            self::fail('A denied worker result was accepted.');
        } catch (ColorTemplateAccessDenied) {
        }
        $this->worker('print "KADUPUL_COLOR_SYNC_RESULT=" . json_encode(["status" => "changed", "summary" => null]) . "\n"; exit(1);');
        try {
            $this->adapter()->synchronize(42, 7, 'revision');
            self::fail('A changed worker result was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(LegacyColorTemplateStore::CHANGED, $error->getMessage());
        }
        self::assertSame([AuditEvent::DENIED, AuditEvent::DENIED], array_map(static fn(AuditEvent $event): string => $event->outcome, $this->events));
    }

    /** @return iterable<string, array{string}> */
    public static function unconfirmed(): iterable
    {
        $ok = '{"status":"ok","summary":{"template":"Reds","aggregate_templates":1,"aggregate_graphs":0}}';
        yield 'failed' => ['print "KADUPUL_COLOR_SYNC_RESULT={\"status\":\"failed\",\"summary\":null}\n"; exit(1);'];
        yield 'no marker' => ['print "done\n";'];
        yield 'two markers' => ['print "KADUPUL_COLOR_SYNC_RESULT=' . addslashes($ok) . '\nKADUPUL_COLOR_SYNC_RESULT=' . addslashes($ok) . '\n";'];
        yield 'ok with failing exit' => ['print "KADUPUL_COLOR_SYNC_RESULT=' . addslashes($ok) . '\n"; exit(1);'];
        yield 'malformed json' => ['print "KADUPUL_COLOR_SYNC_RESULT={broken}\n";'];
        yield 'negative count' => ['print "KADUPUL_COLOR_SYNC_RESULT={\"status\":\"ok\",\"summary\":{\"template\":\"Reds\",\"aggregate_templates\":-1,\"aggregate_graphs\":0}}\n";'];
    }

    #[DataProvider('unconfirmed')]
    public function testUnconfirmedOutcomesAreNeverReportedAsSynchronized(string $body): void
    {
        $this->worker($body);
        try {
            $this->adapter()->synchronize(42, 7, 'revision');
            self::fail('An unconfirmed synchronization was reported as complete.');
        } catch (\RuntimeException $error) {
            self::assertStringStartsWith('Color Template synchronization', $error->getMessage());
        }
        self::assertSame([AuditEvent::FAILED], array_map(static fn(AuditEvent $event): string => $event->outcome, $this->events));
    }

    private function worker(string $body): void
    {
        file_put_contents($this->directory . '/bin/legacy-color-template-sync.php', '<?php ' . $body);
    }

    private function adapter(): LegacyColorTemplateSynchronization
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_php_binary', PHP_BINARY]);
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $audit = $this->createMock(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });
        return new LegacyColorTemplateSynchronization($database, $audit, $this->directory);
    }
}
