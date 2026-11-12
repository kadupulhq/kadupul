<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\DataInput\Application\DataInputConflict;
use Kadupul\DataInput\Application\DataInputDenied;
use Kadupul\DataInput\Domain\DataInputNotFound;
use Kadupul\DataInput\Domain\DataQueryRejected;
use Kadupul\DataInput\Domain\DataQuerySettings;
use Kadupul\DataInput\Infrastructure\Legacy\LegacyDataQueryGateway;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The worker answer is trusted only when it is framed, bound to this request and well formed. */
final class DataQueryGatewayTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            foreach (['bin/legacy-data-query.php', 'mode.json', 'command.json'] as $file) {
                if (is_file($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                }
            }
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }

    #[DataProvider('rejectedSaves')]
    public function testRejectedWorkerAnswersCannotConfirmASave(string $mode, string $exception, string $message, string $outcome): void
    {
        [$gateway, $audit] = $this->gateway($mode);
        try {
            $gateway->save(9, 3, self::settings(), 'rev');
            self::fail('A rejected answer confirmed the save.');
        } catch (\Throwable $error) {
            self::assertInstanceOf($exception, $error);
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertCount(1, $audit->events);
        self::assertSame(['data-query.save', 'data-query', '3', $outcome], [$audit->events[0]->action, $audit->events[0]->targetType, $audit->events[0]->targetId, $audit->events[0]->outcome]);
    }

    public static function rejectedSaves(): iterable
    {
        foreach (['actor', 'action', 'request_id', 'nonce', 'result'] as $key) {
            yield $key => [$key, \RuntimeException::class, 'could not be verified', AuditEvent::FAILED];
        }
        yield 'missing framing' => ['missing', \RuntimeException::class, 'outcome is unknown', AuditEvent::FAILED];
        yield 'malformed JSON' => ['malformed', \RuntimeException::class, 'outcome is unknown', AuditEvent::FAILED];
        yield 'malformed braced JSON' => ['malformed_braced', \RuntimeException::class, 'outcome is unknown', AuditEvent::FAILED];
        yield 'other id saved' => ['target', \RuntimeException::class, 'Save outcome is unknown', AuditEvent::FAILED];
        yield 'partial not boolean' => ['partial_type', \RuntimeException::class, 'Save outcome is unknown', AuditEvent::FAILED];
        yield 'worker failure' => ['failed', \RuntimeException::class, 'Operation failed', AuditEvent::FAILED];
        yield 'ok with failed exit' => ['exit', \RuntimeException::class, 'Operation failed', AuditEvent::FAILED];
        yield 'grant denied' => ['denied', DataInputDenied::class, 'Access denied', AuditEvent::DENIED];
        yield 'stale revision' => ['conflict', DataInputConflict::class, 'changed since you opened', AuditEvent::FAILED];
        yield 'missing query' => ['not_found', DataInputNotFound::class, 'Data query not found.', AuditEvent::FAILED];
        yield 'refused settings' => ['invalid', DataQueryRejected::class, 'readable XML file', AuditEvent::FAILED];
    }

    public function testARefusalKeepsItsFieldAndParameters(): void
    {
        [$gateway] = $this->gateway('invalid');
        try {
            $gateway->save(9, 3, self::settings(), 'rev');
            self::fail('The refusal was not raised.');
        } catch (DataQueryRejected $error) {
            self::assertSame(['xml_path', ['%count%' => '255']], [$error->field, $error->parameters]);
        }
    }

    public function testConfirmedSavesAuditTheirOutcome(): void
    {
        foreach (['ok' => AuditEvent::SUCCEEDED, 'partial' => AuditEvent::FAILED, 'audit_failure' => AuditEvent::SUCCEEDED] as $mode => $outcome) {
            [$gateway, $audit, $directory] = $this->gateway($mode);
            self::assertSame(['id' => 3, 'partial' => $mode === 'partial'], $gateway->save(9, 3, self::settings(), 'rev'), $mode);
            self::assertSame([AuditEvent::ALLOWED, $outcome], [$audit->events[0]->decision, $audit->events[0]->outcome], $mode);
            // The worker receives exactly the validated columns and the revision.
            $command = json_decode((string) file_get_contents($directory . '/command.json'), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame(['settings' => self::settings()->columns(), 'revision' => 'rev'], $command['payload']);
            self::assertSame([9, 'save', 3], [$command['actor'], $command['action'], $command['id']]);
        }
        [$gateway, $audit] = $this->gateway('created');
        self::assertSame(['id' => 11, 'partial' => false], $gateway->save(9, 0, self::settings(), ''));
        self::assertSame('11', $audit->events[0]->targetId);
    }

    #[DataProvider('findAnswers')]
    public function testFindVerifiesTheRowAndChoices(string $mode, int $id, bool $valid): void
    {
        [$gateway] = $this->gateway($mode);
        if (!$valid) {
            $this->expectException(\RuntimeException::class);
        }
        $state = $gateway->find(9, $id);
        self::assertSame([2 => 'SNMP'], $state['inputs']);
        self::assertSame($id === 0 ? null : 3, $state['query']['id'] ?? null);
    }

    public static function findAnswers(): iterable
    {
        yield 'stored query' => ['find', 3, true];
        yield 'new query' => ['find_new', 0, true];
        yield 'row for another id' => ['find_target', 3, false];
        yield 'row with a wrong type' => ['find_type', 3, false];
        yield 'no row for an existing id' => ['find_new', 3, false];
        yield 'unknown XML state' => ['find_xml', 3, false];
        yield 'choice name not text' => ['find_choice', 3, false];
    }

    private static function settings(): DataQuerySettings
    {
        return new DataQuerySettings('Interfaces', '', '<path_cacti>/resource/snmp_queries/interface.xml', 2);
    }

    /** @return array{LegacyDataQueryGateway, object{events: list<AuditEvent>}, string} */
    private function gateway(string $mode): array
    {
        $directory = sys_get_temp_dir() . '/data-query-gateway-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        $this->directories[] = $directory;
        file_put_contents($directory . '/mode.json', json_encode($mode, JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/bin/legacy-data-query.php', <<<'PHP'
<?php
$input = stream_get_contents(STDIN);
file_put_contents('command.json', $input);
$c = json_decode($input, true);
$mode = json_decode(file_get_contents('mode.json'), true);
$row = ['id' => $c['id'], 'name' => 'Interfaces', 'description' => '', 'xml_path' => 'x', 'data_input_id' => 2];
$result = str_starts_with($mode, 'find')
    ? ['query' => $mode === 'find_new' ? null : $row, 'revision' => 'rev', 'xml' => 'found', 'inputs' => ['2' => 'SNMP']]
    : ['id' => $c['id'] > 0 ? $c['id'] : 11, 'partial' => $mode === 'partial'];
if ($mode === 'find_target') $result['query']['id'] = 4;
if ($mode === 'find_type') $result['query']['data_input_id'] = '2';
if ($mode === 'find_xml') $result['xml'] = 'maybe';
if ($mode === 'find_choice') $result['inputs'] = [2 => 7];
if ($mode === 'target') $result['id'] = 4;
if ($mode === 'partial_type') $result['partial'] = 'no';
$r = ['actor' => $c['actor'], 'action' => $c['action'], 'request_id' => $c['id'], 'nonce' => $c['nonce'], 'status' => 'ok', 'result' => $result];
if (in_array($mode, ['actor', 'action', 'request_id', 'nonce', 'result'], true)) $r[$mode] = 'mismatch';
if (in_array($mode, ['denied', 'conflict', 'failed', 'not_found', 'invalid'], true)) $r['status'] = $mode;
if ($mode === 'not_found') $r['result'] = ['message' => 'Data query not found.'];
if ($mode === 'invalid') $r['result'] = ['message' => 'Enter the path of a readable XML file under the resource directory.', 'parameters' => ['%count%' => '255', 'bad' => 1], 'field' => 'xml_path'];
if ($mode === 'missing') { echo 'NO_RESULT'; exit; }
if ($mode === 'malformed') { echo 'KADUPUL_DATA_QUERY_RESULT=broken', PHP_EOL; exit; }
if ($mode === 'malformed_braced') { echo 'KADUPUL_DATA_QUERY_RESULT={broken}', PHP_EOL; exit; }
echo "legacy output\n", 'KADUPUL_DATA_QUERY_RESULT=', json_encode($r), PHP_EOL;
exit($mode === 'exit' ? 1 : 0);
PHP);
        $audit = new class ($mode === 'audit_failure') implements AuditTrail {
            /** @var list<AuditEvent> */
            public array $events = [];

            public function __construct(private readonly bool $fail) {}

            public function record(AuditEvent $event): void
            {
                $this->events[] = $event;
                if ($this->fail) {
                    throw new \RuntimeException('Audit sink unavailable.');
                }
            }
        };
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings(name TEXT, value TEXT)');
        $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_php_binary', PHP_BINARY]);
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);

        return [new LegacyDataQueryGateway($database, $audit, $directory), $audit, $directory];
    }
}
