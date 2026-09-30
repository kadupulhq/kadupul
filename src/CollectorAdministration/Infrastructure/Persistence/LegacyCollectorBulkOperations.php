<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Persistence;

use Kadupul\CollectorAdministration\Application\Port\CollectorBulkOperations;
use Kadupul\CollectorAdministration\Domain\CollectorBulkAction;
use Kadupul\CollectorAdministration\Domain\CollectorSelection;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\Process\Process;

final readonly class LegacyCollectorBulkOperations implements CollectorBulkOperations
{
    public function __construct(
        private DatabaseConnection $database,
        private LegacyConfiguration $configuration,
        private PdoCollectorOperatorAuthorization $authorization,
        private AuditTrail $audit,
        private string $projectDir
    ) {}

    public function find(CollectorSelection $selection): array
    {
        $ids = $selection->ids;
        $query = $this->database->get()->prepare('SELECT id, name, dbhost FROM poller WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $query->execute($ids);
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'name' => trim((string) $row['name']) === '' ? '<no name>' : (string) $row['name'],
            'dbhost' => (string) ($row['dbhost'] ?? ''),
        ], $query->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function execute(int $actorId, CollectorBulkAction $action, CollectorSelection $selection): array
    {
        $connection = $this->database->get();
        if ($action !== CollectorBulkAction::FullSync) {
            try {
                $result = (new PdoCollectorBulkMutation())->apply(
                    $connection,
                    $actorId,
                    $action,
                    $selection,
                    function (\PDO $transaction, int $verifiedActorId): void {
                        try {
                            $this->authorization->assertCanManage($transaction, $verifiedActorId);
                        } catch (CollectorBulkAccessDenied) {
                            throw new CollectorAccessDenied(false);
                        }
                    }
                );
                $this->recordAudit($actorId, $action, $result['successful'], $result['failed']);
                return $result;
            } catch (CollectorBulkAccessDenied) {
                $this->recordAudit($actorId, $action, [], $selection->ids, AuditEvent::DENIED);
                throw new CollectorAccessDenied(false);
            } catch (CollectorAccessDenied $error) {
                $this->recordAudit($actorId, $action, [], $selection->ids, AuditEvent::DENIED);
                throw $error;
            } catch (\Throwable $error) {
                $this->recordAudit($actorId, $action, [], $selection->ids);
                throw $error;
            }
        }
        $installation = $this->configuration->values();
        if (($installation['collector_id'] ?? null) !== 1) {
            throw new \RuntimeException('Full synchronization is only available from the primary data collector.');
        }
        $binary = $connection->query("SELECT value FROM settings WHERE name = 'path_php_binary'")->fetchColumn();
        $binary = is_string($binary) && trim($binary) !== '' ? trim($binary) : PHP_BINARY;
        $process = new Process([$binary, $this->projectDir . '/bin/legacy-collector-bulk-action.php'], $this->projectDir);
        $process->setTimeout($action === CollectorBulkAction::FullSync ? 900 : 120);
        $process->setInput(json_encode([
            'actor' => $actorId,
            'action' => $action->value,
            'ids' => $selection->ids,
        ], JSON_THROW_ON_ERROR));
        $process->run();

        if (!preg_match('/KADUPUL_COLLECTOR_ACTION_RESULT=(\{[^\r\n]+\})/', $process->getOutput(), $match)) {
            throw new \RuntimeException('Data collector operation outcome is unknown.');
        }
        try {
            $result = json_decode($match[1], true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Data collector operation outcome is unknown.', 0, $error);
        }
        if (!$process->isSuccessful() || !is_array($result)
            || ($result['actor'] ?? null) !== $actorId || ($result['action'] ?? null) !== $action->value
            || !isset($result['successful'], $result['failed'])
            || !is_array($result['successful']) || !is_array($result['failed'])) {
            throw new \RuntimeException('Data collector operation outcome is unknown.');
        }
        foreach (['successful', 'failed'] as $key) {
            if (array_filter($result[$key], static fn(mixed $id): bool => !is_int($id) || $id < 1) !== []) {
                throw new \RuntimeException('Data collector operation outcome is unknown.');
            }
        }
        $successful = $result['successful'];
        $failed = $result['failed'];
        sort($successful, SORT_NUMERIC);
        sort($failed, SORT_NUMERIC);
        $reported = array_merge($successful, $failed);
        sort($reported, SORT_NUMERIC);
        if (count(array_unique($reported)) !== count($reported) || $reported !== $selection->ids) {
            throw new \RuntimeException('Data collector operation outcome is unknown.');
        }
        $verified = ['successful' => $successful, 'failed' => $failed];
        $this->recordAudit($actorId, $action, $verified['successful'], $verified['failed']);
        return $verified;
    }

    private function recordAudit(int $actorId, CollectorBulkAction $action, array $successful, array $failed, string $decision = AuditEvent::ALLOWED): void
    {
        try {
            $correlationId = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return;
        }
        foreach (array_merge($successful, $failed) as $collectorId) {
            try {
                $this->audit->record(new AuditEvent(
                    $correlationId,
                    $actorId,
                    'collector.' . $action->value,
                    'collector',
                    (string) $collectorId,
                    $decision,
                    $decision === AuditEvent::DENIED
                        ? AuditEvent::DENIED
                        : (in_array($collectorId, $successful, true) ? AuditEvent::SUCCEEDED : AuditEvent::FAILED)
                ));
            } catch (\Throwable) {
                // The audit sink must not obscure the resolved database or remote operation outcome.
            }
        }
    }
}
