<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Infrastructure\Legacy;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateSynchronizer;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\Process\Process;

final readonly class LegacyColorTemplateSynchronizer implements ColorTemplateSynchronizer
{
    public function __construct(private DatabaseConnection $database, private AuditTrail $audit, private string $projectDir) {}

    public function sync(int $actorId, int $templateId): array
    {
        $status = AuditEvent::FAILED;
        $summary = [];
        try {
            $query = $this->database->get()->query("SELECT value FROM settings WHERE name='path_php_binary'");
            if ($query === false) {
                throw new \RuntimeException('Color template worker configuration could not be verified.');
            }
            $binary = $query->fetchColumn();
            if ($query->errorCode() !== '00000') {
                throw new \RuntimeException('Color template worker configuration could not be verified.');
            }
            $binary = is_string($binary) && trim($binary) !== '' ? trim($binary) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
            $process = new Process([$binary, $this->projectDir . '/bin/legacy-color-template-sync.php'], $this->projectDir);
            $process->setTimeout(180);
            $process->setInput(json_encode(['actor' => $actorId, 'template_id' => $templateId], JSON_THROW_ON_ERROR));
            $process->run();
            $output = $process->getOutput();
            if (preg_match_all('/^KADUPUL_COLOR_SYNC_RESULT=(\{[^\r\n]+\})$/m', $output, $matches) !== 1
                || trim($output) !== 'KADUPUL_COLOR_SYNC_RESULT=' . $matches[1][0]) {
                throw new \RuntimeException('Color template synchronization outcome is unknown.');
            }
            try {
                $wireResult = json_decode($matches[1][0], false, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new \RuntimeException('Color template synchronization outcome is unknown.', 0, $error);
            }
            if (!$wireResult instanceof \stdClass) {
                throw new \RuntimeException('Color template synchronization could not be confirmed.');
            }
            $result = get_object_vars($wireResult);
            if (($result['actor'] ?? null) !== $actorId || ($result['template_id'] ?? null) !== $templateId) {
                throw new \RuntimeException('Color template synchronization could not be confirmed.');
            }
            if (($result['status'] ?? '') === 'denied') {
                $status = AuditEvent::DENIED;
                throw new \Kadupul\ColorTemplates\Application\Query\ColorTemplateAccessDenied(false);
            }
            if (($result['status'] ?? '') === 'invalid') {
                $status = AuditEvent::DENIED;
                throw new \InvalidArgumentException('Color template not found.');
            }
            if (!$process->isSuccessful() || ($result['status'] ?? '') !== 'ok' || !(($result['summary'] ?? null) instanceof \stdClass)) {
                $diagnostic = $result['diagnostic'] ?? null;
                if (is_string($diagnostic) && preg_match('/\A[A-Za-z0-9_\\\\]+ in [A-Za-z0-9_.-]+:[0-9]+(?: \((?:missing [A-Za-z0-9_\\\\]+|database error [0-9]+)\))?\z/D', $diagnostic)) {
                    error_log('Color template sync worker failed: ' . $diagnostic);
                }
                throw new \RuntimeException('Color template synchronization could not be confirmed.');
            }
            $summary = get_object_vars($result['summary']);
            if (count($summary) !== 3 || !is_string($summary['template'] ?? null)
                || !is_int($summary['aggregate_templates'] ?? null) || $summary['aggregate_templates'] < 0
                || !is_int($summary['aggregate_graphs'] ?? null) || $summary['aggregate_graphs'] < 0) {
                throw new \RuntimeException('Color template synchronization could not be confirmed.');
            }
            $status = AuditEvent::SUCCEEDED;
            return $summary;
        } catch (\Throwable $error) {
            if ($status === AuditEvent::FAILED && $error instanceof \InvalidArgumentException) {
                $status = AuditEvent::DENIED;
            }
            throw $error;
        } finally {
            try {
                $decision = $status === AuditEvent::DENIED ? AuditEvent::DENIED : AuditEvent::ALLOWED;
                $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, 'color.template.sync', 'color_template', (string) $templateId, $decision, $status));
            } catch (\Throwable) {
            }
        }
    }
}
