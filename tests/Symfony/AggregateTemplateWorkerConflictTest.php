<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\AggregateTemplate\Infrastructure\Legacy\LegacyAggregateTemplateEditor;
use Kadupul\AggregateTemplate\Infrastructure\Persistence\AggregateTemplateConflict;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AggregateTemplateWorkerConflictTest extends TestCase
{
    public static function unverifiedIdentities(): iterable
    {
        yield 'string identity' => [7, ['7']];
        yield 'fractional identity' => [7, [7.5]];
        yield 'negative identity' => [7, [-7]];
        yield 'missing identity' => [7, []];
        yield 'identity object' => [7, [1 => 7]];
        yield 'numeric-key identity object' => [7, (object) ['0' => 7]];
        yield 'multiple success records' => [7, [7], true];
        yield 'malformed extra result' => [7, [7], false, 'KADUPUL_AGGREGATE_RESULT=not-json'];
        yield 'new success without an identity' => [0, [0]];
    }

    #[DataProvider('unverifiedIdentities')]
    public function testWorkerSuccessCannotCoerceAnUnverifiedIdentity(int $id, array|\stdClass $ids, bool $duplicateRecord = false, string $extraRecord = ''): void
    {
        $directory = sys_get_temp_dir() . '/aggregate-identity-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        $worker = $directory . '/bin/legacy-aggregate-template.php';
        $result = ['actor' => 42, 'action' => 'save', 'ids' => $ids, 'status' => 'ok'];
        $record = 'KADUPUL_AGGREGATE_RESULT=' . json_encode($result);
        $output = $duplicateRecord ? $record . "\n" . $record : $record;
        if ($extraRecord !== '') {
            $output .= "\n" . $extraRecord;
        }
        file_put_contents($worker, '<?php stream_get_contents(STDIN); echo '
            . var_export($output, true) . ';');
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_php_binary', PHP_BINARY]);
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $audit = $this->createMock(AuditTrail::class);
            $audit->expects(self::once())->method('record')->with(self::callback(
                static fn(AuditEvent $event): bool => $event->outcome === AuditEvent::FAILED
            ));
            $editor = new LegacyAggregateTemplateEditor($database, $audit, $directory);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($duplicateRecord || $extraRecord !== ''
                ? 'Aggregate template operation outcome is unknown.'
                : 'Aggregate template operation outcome could not be verified.');
            $editor->save(42, $id, [], $id > 0 ? 'old' : '');
        } finally {
            unlink($worker);
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }

    public static function deniedActions(): iterable
    {
        foreach (['save', 'delete'] as $action) {
            yield $action => [$action, 42, $action, 1, true];
            yield $action . ' wrong actor' => [$action, 43, $action, 1, false];
            yield $action . ' string actor' => [$action, '42', $action, 1, false];
            yield $action . ' wrong action' => [$action, 42, 'other', 1, false];
            yield $action . ' wrong exit' => [$action, 42, $action, 0, false];
        }
    }

    #[DataProvider('deniedActions')]
    public function testActorBoundDeniedEnvelopeNeedsNoTargetIds(string $action, int|string $reportedActor, string $reportedAction, int $exitCode, bool $verified): void
    {
        $directory = sys_get_temp_dir() . '/aggregate-denied-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        $worker = $directory . '/bin/legacy-aggregate-template.php';
        $result = ['actor' => $reportedActor, 'action' => $reportedAction, 'ids' => [], 'status' => 'denied'];
        file_put_contents($worker, '<?php stream_get_contents(STDIN); echo ' . var_export('KADUPUL_AGGREGATE_RESULT=' . json_encode($result), true) . '; exit(' . $exitCode . ');');
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_php_binary', PHP_BINARY]);
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $audit = $this->createMock(AuditTrail::class);
            $audit->expects(self::once())->method('record')->with(self::callback(static fn(AuditEvent $event): bool => $event->decision === ($verified ? AuditEvent::DENIED : AuditEvent::ALLOWED) && $event->outcome === ($verified ? AuditEvent::DENIED : AuditEvent::FAILED)));
            $editor = new LegacyAggregateTemplateEditor($database, $audit, $directory);
            $this->expectException($verified ? \Kadupul\AggregateTemplate\Application\Query\AggregateTemplateAccessDenied::class : \RuntimeException::class);
            if ($action === 'save') {
                $editor->save(42, 7, [], 'old');
            } else {
                $editor->delete(42, [7 => 'old']);
            }
        } finally {
            unlink($worker);
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }

    public static function actions(): iterable
    {
        yield 'save' => ['save', 'Aggregate template changed. Reload before saving.'];
        yield 'delete' => ['delete', 'Aggregate template changed. Reload before deleting.'];
    }

    #[DataProvider('actions')]
    public function testVerifiedWorkerConflictUsesTheRequestedAction(string $action, string $message): void
    {
        $directory = sys_get_temp_dir() . '/aggregate-conflict-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        $worker = $directory . '/bin/legacy-aggregate-template.php';
        $command = $action === 'save'
            ? ['actor' => 42, 'action' => 'save', 'id' => 7, 'revision' => 'old', 'data' => []]
            : ['actor' => 42, 'action' => 'delete', 'revisions' => [7 => 'old']];
        $result = ['actor' => 42, 'action' => $action, 'ids' => [7], 'status' => 'conflict'];
        file_put_contents($worker, '<?php $input = json_decode(stream_get_contents(STDIN), true);'
            . ' if ($input !== ' . var_export($command, true) . ') { exit(9); }'
            . ' echo ' . var_export('KADUPUL_AGGREGATE_RESULT=' . json_encode($result), true) . '; exit(1);');
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_php_binary', PHP_BINARY]);
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $audit = $this->createMock(AuditTrail::class);
            $audit->expects(self::once())->method('record')->with(self::callback(
                static fn(AuditEvent $event): bool => $event->action === 'aggregate-template.' . $action
                    && $event->outcome === AuditEvent::FAILED
            ));
            $editor = new LegacyAggregateTemplateEditor($database, $audit, $directory);
            $this->expectException(AggregateTemplateConflict::class);
            $this->expectExceptionMessage($message);
            if ($action === 'save') {
                $editor->save(42, 7, [], 'old');
            } else {
                $editor->delete(42, [7 => 'old']);
            }
        } finally {
            unlink($worker);
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }
}
