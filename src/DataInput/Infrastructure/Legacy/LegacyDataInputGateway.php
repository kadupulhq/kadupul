<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace Kadupul\DataInput\Infrastructure\Legacy;

use Kadupul\DataInput\Application\Port\DataInputGateway;
use Kadupul\DataInput\Application\DataInputDenied;
use Kadupul\DataInput\Application\DataInputConflict;
use Kadupul\DataInput\Domain\DataInputNotFound;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\Process\Process;

final readonly class LegacyDataInputGateway implements DataInputGateway
{
    public function __construct(private DatabaseConnection $database, private AuditTrail $audit, private string $projectDir) {}
    public function execute(int $actorId, string $action, int $id, array $payload = []): array
    {
        $nonce = bin2hex(random_bytes(16));
        $bulk = in_array($action, ['bulk_delete', 'bulk_duplicate'], true);
        $selectedIds = [];
        $auditTargets = [$id > 0 ? $id : 'unknown'];
        if ($bulk && is_array($payload['selection'] ?? null)) {
            try {
                $selectedIds = $this->validatedIds(array_keys($payload['selection']));
                $auditTargets = $selectedIds;
            } catch (\RuntimeException) {
                // Invalid selections still reach the worker's validation boundary.
            }
        }

        $command = ['actor' => $actorId, 'action' => $action, 'id' => $id, 'payload' => $payload, 'nonce' => $nonce];
        $configured = $this->database->get()->query("SELECT value FROM settings WHERE name='path_php_binary'")->fetchColumn();
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $process = new Process([$binary, $this->projectDir . '/bin/legacy-data-input.php'], $this->projectDir);
        $process->setInput(json_encode($command, JSON_THROW_ON_ERROR));
        $process->setTimeout(180);
        $outcome = AuditEvent::FAILED;
        $decision = AuditEvent::ALLOWED;
        try {
            $process->run();
            if (!preg_match('/^KADUPUL_DATA_INPUT_RESULT=(\{[^\r\n]+\})$/m', $process->getOutput(), $match)) {
                throw new \RuntimeException('Operation outcome is unknown. Reload before retrying.');
            }
            $response = json_decode($match[1], true, 16, JSON_THROW_ON_ERROR);
            if (($response['actor'] ?? null) !== $actorId || ($response['action'] ?? null) !== $action || ($response['request_id'] ?? null) !== $id || ($response['nonce'] ?? null) !== $nonce || !is_array($response['result'] ?? null)) {
                throw new \RuntimeException('Operation outcome could not be verified.');
            }
            $status = $response['status'] ?? '';
            if ($status === 'denied') {
                $decision = AuditEvent::DENIED;
                $outcome = AuditEvent::DENIED;
                throw new DataInputDenied();
            }
            if ($status === 'conflict') {
                throw new DataInputConflict('Data input changed. Reload before saving.');
            }
            if ($status === 'not_found') {
                throw new DataInputNotFound($response['result']['message'] ?? 'Data input not found.');
            }
            if ($status === 'invalid') {
                throw new \InvalidArgumentException($response['result']['message'] ?? 'Invalid data input.');
            }
            if (!$process->isSuccessful() || !in_array($status, ['ok', 'partial'], true)) {
                throw new \RuntimeException('Operation failed. Reload before retrying.');
            }
            $result = $response['result'];
            if (!in_array($action, ['list', 'find', 'selection', 'bulk_delete', 'bulk_duplicate'], true)) {
                if (!is_int($result['id'] ?? null) || $result['id'] < 1 || ($action !== 'duplicate' && $id > 0 && $result['id'] !== $id)) {
                    throw new \RuntimeException('Operation target could not be verified.');
                }
            }
            if ($action === 'find' && $id > 0 && (int) ($result['method']['id'] ?? 0) !== $id) {
                throw new \RuntimeException('Operation target could not be verified.');
            }
            if ($action === 'selection') {
                $selection = $result['selection'] ?? null;
                $names = $result['names'] ?? null;
                if (!is_array($selection) || !is_array($names) || array_keys($selection) !== ($payload['ids'] ?? null) || !array_is_list($names) || count($names) !== count($selection)) {
                    throw new \RuntimeException('Operation target could not be verified.');
                }
                foreach ($selection as $revision) {
                    if (!is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D', $revision)) {
                        throw new \RuntimeException('Operation target could not be verified.');
                    }
                }
                foreach ($names as $name) {
                    if (!is_string($name)) {
                        throw new \RuntimeException('Operation target could not be verified.');
                    }
                }
            }
            if ($bulk) {
                $affectedIds = $this->validatedIds($result['ids'] ?? null);
                if (count($affectedIds) !== count($selectedIds) || ($action === 'bulk_delete' && $affectedIds !== $selectedIds)) {
                    throw new \RuntimeException('Operation target could not be verified.');
                }
                $auditTargets = $affectedIds;
            } elseif (is_int($result['id'] ?? null) && $result['id'] > 0) {
                $auditTargets = [$result['id']];
            }
            $outcome = $status === 'ok' ? AuditEvent::SUCCEEDED : AuditEvent::FAILED;
            return $response['result'] + ['partial' => $status === 'partial'];
        } finally {
            if (!in_array($action, ['list', 'find', 'selection'], true)) {
                foreach ($auditTargets as $target) {
                    try {
                        $this->audit->record(new AuditEvent($nonce, $actorId, 'data-input.' . $action, 'data-input', (string) $target, $decision, $outcome));
                    } catch (\Throwable) { /* Audit cannot overwrite confirmed persistence or suppress later targets. */
                    }
                }
            }
        }
    }
    private function validatedIds(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 100) {
            throw new \RuntimeException('Operation target could not be verified.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1 || $id > 99999999) {
                throw new \RuntimeException('Operation target could not be verified.');
            }
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \RuntimeException('Operation target could not be verified.');
        }
        return $ids;
    }
}
