<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace Kadupul\Tests;

use Kadupul\DataInput\Application\DataInputConflict;
use Kadupul\DataInput\Application\DataInputDenied;
use Kadupul\DataInput\Infrastructure\Legacy\LegacyDataInputGateway;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataInputGatewayTest extends TestCase
{
    #[DataProvider('rejectedResponses')]
    public function testRejectedWorkerResponsesCannotConfirmMutation(string $mode, string $exception, string $message): void
    {
        [$gateway, $audit, $directory] = $this->gateway($mode);
        try {
            try {
                $gateway->execute(9, 'save', 3);
                self::fail('Rejected response confirmed the mutation.');
            } catch (\Throwable $error) {
                self::assertInstanceOf($exception, $error);
                self::assertStringContainsString($message, $error->getMessage());
            }
            self::assertCount(1, $audit->events);
            self::assertSame($mode === 'denied' ? AuditEvent::DENIED : AuditEvent::FAILED, $audit->events[0]->outcome);
        } finally {
            $this->cleanup($directory);
        }
    }
    public static function rejectedResponses(): iterable
    {
        foreach (['actor', 'action', 'request_id', 'nonce', 'result'] as $key) {
            yield $key => [$key, \RuntimeException::class, 'could not be verified'];
        }
        yield 'missing framing' => ['missing', \RuntimeException::class, 'outcome is unknown'];
        yield 'malformed JSON' => ['malformed', \JsonException::class, 'Syntax error'];
        yield 'target mismatch' => ['target', \RuntimeException::class, 'target could not be verified'];
        yield 'worker failure' => ['failed', \RuntimeException::class, 'Operation failed'];
        yield 'worker exit' => ['exit', \RuntimeException::class, 'Operation failed'];
        yield 'grant denied' => ['denied', DataInputDenied::class, 'Access denied'];
        yield 'stale revision' => ['conflict', DataInputConflict::class, 'changed'];
        yield 'validation rejected' => ['invalid', \InvalidArgumentException::class, 'Invalid input fixture'];
    }
    #[DataProvider('acceptedResponses')]
    public function testConfirmedAndPartialResponsesKeepTheirAuditMeaning(string $mode, string $action, bool $partial, int $id): void
    {
        [$gateway, $audit, $directory] = $this->gateway($mode);
        try {
            $result = $gateway->execute(9, $action, 3);
            self::assertSame($id, $result['id']);
            self::assertSame($partial, $result['partial']);
            self::assertCount(1, $audit->events);
            self::assertSame($partial ? AuditEvent::FAILED : AuditEvent::SUCCEEDED, $audit->events[0]->outcome);
            self::assertSame(AuditEvent::ALLOWED, $audit->events[0]->decision);
        } finally {
            $this->cleanup($directory);
        }
    }
    public static function acceptedResponses(): iterable
    {
        yield ['ok', 'save', false, 3];
        yield ['partial', 'propagate', true, 3];
        yield ['duplicate', 'duplicate', false, 4];
        yield ['audit_failure', 'save', false, 3];
    }
    #[DataProvider('selectionResponses')]
    public function testBulkSelectionResponsesAreVerifiedBeforePresentation(string $mode, bool $valid): void
    {
        [$gateway, $audit, $directory] = $this->gateway($mode);
        try {
            if (!$valid) {
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('target could not be verified');
            }
            $result = $gateway->execute(9, 'selection', 0, ['ids' => [3]]);
            self::assertSame([3 => str_repeat('a', 64)], $result['selection']);
            self::assertSame(['Fixture'], $result['names']);
            self::assertSame([], $audit->events);
        } finally {
            $this->cleanup($directory);
        }
    }

    public static function selectionResponses(): iterable
    {
        yield ['selection_ok', true];
        yield ['selection_id', false];
        yield ['selection_revision', false];
        yield ['selection_name', false];
    }

    private function gateway(string $mode): array
    {
        $directory = sys_get_temp_dir() . '/data-input-gateway-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        file_put_contents($directory . '/mode.json', json_encode($mode, JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/bin/legacy-data-input.php', <<<'PHP'
<?php
$c=json_decode(stream_get_contents(STDIN),true);
$mode=json_decode(file_get_contents('mode.json'),true);
if (str_starts_with($mode, 'selection_')) {
    $result = ['selection' => [3 => str_repeat('a', 64)], 'names' => ['Fixture']];
    if ($mode === 'selection_id') $result['selection'] = [4 => str_repeat('a', 64)];
    if ($mode === 'selection_revision') $result['selection'][3] = 'bad';
    if ($mode === 'selection_name') $result['names'] = [42];
    echo 'KADUPUL_DATA_INPUT_RESULT=', json_encode(['actor' => $c['actor'], 'action' => $c['action'], 'request_id' => $c['id'], 'nonce' => $c['nonce'], 'status' => 'ok', 'result' => $result]), PHP_EOL;
    exit;
}

$r=['actor'=>$c['actor'],'action'=>$c['action'],'request_id'=>$c['id'],'nonce'=>$c['nonce'],'status'=>'ok','result'=>['id'=>$c['id']]];
if (in_array($mode,['actor','action','request_id','nonce','result'],true)) $r[$mode]='mismatch';
if ($mode==='target'||$mode==='duplicate') $r['result']['id']=4;
if (in_array($mode,['denied','conflict','invalid','failed','partial'],true)) $r['status']=$mode;
if ($mode==='invalid') $r['result']['message']='Invalid input fixture';
if ($mode==='missing') { echo 'NO_RESULT'; exit; }
if ($mode==='malformed') { echo 'KADUPUL_DATA_INPUT_RESULT={broken}',PHP_EOL; exit; }
echo 'KADUPUL_DATA_INPUT_RESULT=',json_encode($r),PHP_EOL;
exit($mode==='exit'?1:0);
PHP);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings(name TEXT,value TEXT)');
        $statement = $pdo->prepare('INSERT INTO settings VALUES (?,?)');
        $statement->execute(['path_php_binary', PHP_BINARY]);
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $audit = new class ($mode === 'audit_failure') implements AuditTrail {
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
        return [new LegacyDataInputGateway($database, $audit, $directory), $audit, $directory];
    }
    private function cleanup(string $directory): void
    {
        unlink($directory . '/bin/legacy-data-input.php');
        unlink($directory . '/mode.json');
        rmdir($directory . '/bin');
        rmdir($directory);
    }
}
