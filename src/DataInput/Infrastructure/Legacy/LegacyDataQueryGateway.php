<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\DataInput\Infrastructure\Legacy;

use Kadupul\DataInput\Application\DataInputConflict;
use Kadupul\DataInput\Application\DataInputDenied;
use Kadupul\DataInput\Application\Port\DataQueryGateway;
use Kadupul\DataInput\Domain\DataInputNotFound;
use Kadupul\DataInput\Domain\DataQueryRejected;
use Kadupul\DataInput\Domain\DataQuerySettings;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\Process\Process;

/**
 * Runs bin/legacy-data-query.php. Its answer counts only when it echoes this
 * request's actor, action, id and nonce; anything else is an unknown outcome.
 * Every save attempt is audited, including refusals.
 */
final readonly class LegacyDataQueryGateway implements DataQueryGateway
{
    public const string MARKER = 'KADUPUL_DATA_QUERY_RESULT';
    /** The push-out can rebuild the poller cache of many data sources. */
    private const float TIMEOUT = 300.0;

    public function __construct(private DatabaseConnection $database, private AuditTrail $audit, private string $projectDir) {}

    #[\Override]
    public function find(int $actorId, int $id): array
    {
        $result = $this->run($actorId, 'find', $id, []);
        $query = $result['query'] ?? null;
        $inputs = $result['inputs'] ?? null;
        if (!is_string($result['revision'] ?? null) || !in_array($result['xml'] ?? null, ['found', 'missing', 'none'], true) || !is_array($inputs)
            || ($id === 0) !== ($query === null) || ($query !== null && !self::row($query, $id))) {
            throw new \RuntimeException('Data query could not be verified.');
        }
        $choices = [];
        foreach ($inputs as $key => $name) {
            if ((!is_int($key) && !ctype_digit($key)) || !is_string($name)) {
                throw new \RuntimeException('Data query could not be verified.');
            }
            $choices[(int) $key] = $name;
        }
        /** @var ?array{id: int, name: string, description: string, xml_path: string, data_input_id: int} $query */
        return ['query' => $query, 'revision' => $result['revision'], 'xml' => $result['xml'], 'inputs' => $choices];
    }

    #[\Override]
    public function save(int $actorId, int $id, DataQuerySettings $settings, string $revision): array
    {
        $nonce = bin2hex(random_bytes(16));
        $target = $id > 0 ? (string) $id : 'new';
        $decision = AuditEvent::ALLOWED;
        $outcome = AuditEvent::FAILED;
        try {
            $result = $this->run($actorId, 'save', $id, ['settings' => $settings->columns(), 'revision' => $revision], $nonce);
            $saved = $result['id'] ?? null;
            if (!is_int($saved) || $saved < 1 || ($id > 0 && $saved !== $id) || !is_bool($result['partial'] ?? null)) {
                throw new \RuntimeException('Save outcome is unknown. Reload before retrying.');
            }
            $target = (string) $saved;
            $outcome = $result['partial'] ? AuditEvent::FAILED : AuditEvent::SUCCEEDED;
            return ['id' => $saved, 'partial' => $result['partial']];
        } catch (DataInputDenied $error) {
            $decision = AuditEvent::DENIED;
            $outcome = AuditEvent::DENIED;
            throw $error;
        } finally {
            try {
                $this->audit->record(new AuditEvent($nonce, $actorId, 'data-query.save', 'data-query', $target, $decision, $outcome));
            } catch (\Throwable) {
                // Audit failure does not change the confirmed persistence outcome.
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<mixed> the verified result of an "ok" answer
     */
    private function run(int $actorId, string $action, int $id, array $payload, ?string $nonce = null): array
    {
        $nonce ??= bin2hex(random_bytes(16));
        $setting = $this->database->get()->query("SELECT value FROM settings WHERE name = 'path_php_binary'");
        $configured = $setting === false ? false : $setting->fetchColumn();
        // A web request cannot use PHP_BINARY, which names the server there.
        $binary = is_string($configured) && trim($configured) !== '' ? $configured : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $process = new Process([$binary, $this->projectDir . '/bin/legacy-data-query.php'], $this->projectDir);
        $process->setInput(json_encode(['actor' => $actorId, 'action' => $action, 'id' => $id, 'nonce' => $nonce, 'payload' => $payload], JSON_THROW_ON_ERROR));
        $process->setTimeout(self::TIMEOUT);
        $process->run();
        if (preg_match('/^' . self::MARKER . '=(\{[^\r\n]+\})$/m', $process->getOutput(), $match) !== 1) {
            throw new \RuntimeException('Operation outcome is unknown. Reload before retrying.');
        }
        $response = json_decode($match[1], true, 16);
        if (!is_array($response)) {
            throw new \RuntimeException('Operation outcome is unknown. Reload before retrying.');
        }
        if (($response['actor'] ?? null) !== $actorId || ($response['action'] ?? null) !== $action
            || ($response['request_id'] ?? null) !== $id || ($response['nonce'] ?? null) !== $nonce || !is_array($response['result'] ?? null)) {
            throw new \RuntimeException('Operation outcome could not be verified.');
        }
        $result = $response['result'];
        $message = is_string($result['message'] ?? null) ? $result['message'] : '';
        switch ($response['status'] ?? null) {
            case 'denied':
                throw new DataInputDenied();
            case 'conflict':
                throw new DataInputConflict('The data query changed since you opened this form. Reload before saving.');
            case 'not_found':
                throw new DataInputNotFound($message !== '' ? $message : 'Data query not found.');
            case 'invalid':
                $parameters = [];
                foreach (is_array($result['parameters'] ?? null) ? $result['parameters'] : [] as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $parameters[$key] = $value;
                    }
                }
                throw new DataQueryRejected($message !== '' ? $message : 'Invalid command.', $parameters, is_string($result['field'] ?? null) ? $result['field'] : null);
            case 'ok':
                if ($process->isSuccessful()) {
                    return $result;
                }
        }
        throw new \RuntimeException('Operation failed. Reload before retrying.');
    }

    /** @param mixed $row */
    private static function row(mixed $row, int $id): bool
    {
        return is_array($row) && ($row['id'] ?? null) === $id && is_string($row['name'] ?? null) && is_string($row['description'] ?? null)
            && is_string($row['xml_path'] ?? null) && is_int($row['data_input_id'] ?? null);
    }
}
