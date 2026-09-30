<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\AggregateTemplate\Infrastructure\Legacy;

use Kadupul\AggregateTemplate\Application\Port\AggregateTemplateEditor;
use Kadupul\AggregateTemplate\Infrastructure\Persistence\AggregateTemplateConflict;
use Kadupul\AggregateTemplate\Application\Query\AggregateTemplateAccessDenied;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\Process\Process;

final readonly class LegacyAggregateTemplateEditor implements AggregateTemplateEditor
{
    public function __construct(
        private DatabaseConnection $database,
        private AuditTrail $audit,
        private string $projectDir,
    ) {}

    public function save(int $actorId, int $id, array $data, string $revision): int
    {
        try {
            $result = $this->run(['actor' => $actorId, 'action' => 'save', 'id' => $id, 'revision' => $revision, 'data' => $data]);
        } catch (\Throwable $error) {
            $this->record(
                $actorId,
                'aggregate-template.save',
                [$id > 0 ? (string) $id : 'new'],
                $error instanceof AggregateTemplateAccessDenied ? AuditEvent::DENIED : AuditEvent::ALLOWED,
                $error instanceof AggregateTemplateAccessDenied ? AuditEvent::DENIED : AuditEvent::FAILED
            );
            throw $error;
        }
        $target = $result['ids'][0] ?? max(1, $id);
        $this->record($actorId, 'aggregate-template.save', [(string) $target], AuditEvent::ALLOWED, AuditEvent::SUCCEEDED);
        return (int) $target;
    }

    public function delete(int $actorId, array $revisions): void
    {
        $ids = array_map('strval', array_keys($revisions));
        sort($ids, SORT_NUMERIC);
        try {
            $this->run(['actor' => $actorId, 'action' => 'delete', 'revisions' => $revisions]);
        } catch (\Throwable $error) {
            $this->record(
                $actorId,
                'aggregate-template.delete',
                $ids,
                $error instanceof AggregateTemplateAccessDenied ? AuditEvent::DENIED : AuditEvent::ALLOWED,
                $error instanceof AggregateTemplateAccessDenied ? AuditEvent::DENIED : AuditEvent::FAILED
            );
            throw $error;
        }
        $this->record($actorId, 'aggregate-template.delete', $ids, AuditEvent::ALLOWED, AuditEvent::SUCCEEDED);
    }

    /** @param array<string,mixed> $command @return array{actor:int,action:string,ids:list<int>,status:string} */
    private function run(array $command): array
    {
        $configured = $this->database->get()->query("SELECT value FROM settings WHERE name = 'path_php_binary'")->fetchColumn();
        $binary = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $process = new Process([$binary, $this->projectDir . '/bin/legacy-aggregate-template.php'], $this->projectDir);
        $process->setTimeout(180);
        $process->setInput(json_encode($command, JSON_THROW_ON_ERROR));
        $process->run();
        if (!preg_match('/^KADUPUL_AGGREGATE_RESULT=(\{[^\r\n]+\})$/m', $process->getOutput(), $match)) {
            throw new \RuntimeException('Aggregate template operation outcome is unknown.');
        }
        try {
            $result = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Aggregate template operation outcome is unknown.', 0, $error);
        }
        $actor = $command['actor'];
        $action = $command['action'];
        $expectedIds = $action === 'save'
            ? [(int) ($command['id'] ?? 0) > 0 ? (int) $command['id'] : (int) ($result['ids'][0] ?? 0)]
            : array_map('intval', array_keys($command['revisions'] ?? []));
        sort($expectedIds, SORT_NUMERIC);
        $actualIds = array_map('intval', is_array($result['ids'] ?? null) ? $result['ids'] : []);
        sort($actualIds, SORT_NUMERIC);
        if (($result['actor'] ?? null) !== $actor || ($result['action'] ?? null) !== $action || $actualIds !== $expectedIds) {
            throw new \RuntimeException('Aggregate template operation outcome could not be verified.');
        }
        if (($result['status'] ?? '') === 'conflict') {
            throw new AggregateTemplateConflict('Aggregate template changed. Reload before saving.');
        }
        if (($result['status'] ?? '') === 'denied') {
            throw new AggregateTemplateAccessDenied();
        }
        if (!$process->isSuccessful() || ($result['status'] ?? '') !== 'ok') {
            throw new \RuntimeException('Aggregate template operation failed.');
        }
        return $result;
    }

    /** @param list<string> $ids */
    private function record(int $actorId, string $action, array $ids, string $decision, string $outcome): void
    {
        $correlation = bin2hex(random_bytes(16));
        foreach ($ids as $id) {
            try {
                $this->audit->record(new AuditEvent($correlation, $actorId, $action, 'aggregate-template', $id, $decision, $outcome));
            } catch (\Throwable) {
                // Audit sink errors cannot replace the worker's confirmed result.
            }
        }
    }
}
