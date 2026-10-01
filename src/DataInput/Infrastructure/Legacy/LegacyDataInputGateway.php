<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace Kadupul\DataInput\Infrastructure\Legacy;

use Kadupul\DataInput\Application\Port\DataInputGateway;
use Kadupul\DataInput\Application\DataInputDenied;
use Kadupul\DataInput\Application\DataInputConflict;
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
        $command = ['actor' => $actorId, 'action' => $action, 'id' => $id, 'payload' => $payload, 'nonce' => $nonce];
        $configured = $this->database->get()->query("SELECT value FROM settings WHERE name='path_php_binary'")->fetchColumn();
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . '/php';
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
            $outcome = $status === 'ok' ? AuditEvent::SUCCEEDED : AuditEvent::FAILED;
            return $response['result'] + ['partial' => $status === 'partial'];
        } finally {
            if (!in_array($action, ['list', 'find', 'selection'], true)) {
                try {
                    $this->audit->record(new AuditEvent($nonce, $actorId, 'data-input.' . $action, 'data-input', (string) ($response['result']['id'] ?? $id), $decision, $outcome));
                } catch (\Throwable) { /* Audit cannot overwrite confirmed persistence. */
                }
            }
        }
    }
}
