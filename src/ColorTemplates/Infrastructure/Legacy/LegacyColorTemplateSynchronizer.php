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
        $binary = $this->database->get()->query("SELECT value FROM settings WHERE name='path_php_binary'")->fetchColumn();
        $binary = is_string($binary) && trim($binary) !== '' ? trim($binary) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $process = new Process([$binary, $this->projectDir . '/bin/legacy-color-template-sync.php'], $this->projectDir);
        $process->setTimeout(180);
        $process->setInput(json_encode(['actor' => $actorId, 'template_id' => $templateId], JSON_THROW_ON_ERROR));
        $status = AuditEvent::FAILED;
        $summary = [];
        try {
            $process->run();
            if (!preg_match('/KADUPUL_COLOR_SYNC_RESULT=(\{[^\r\n]+\})/', $process->getOutput(), $match)) {
                throw new \RuntimeException('Color template synchronization outcome is unknown.');
            }
            $result = json_decode($match[1], true, 8, JSON_THROW_ON_ERROR);
            if (($result['status'] ?? '') === 'denied') {
                $status = AuditEvent::DENIED;
                throw new \Kadupul\ColorTemplates\Application\Query\ColorTemplateAccessDenied(false);
            }
            if (($result['status'] ?? '') === 'invalid') {
                $status = AuditEvent::DENIED;
                throw new \InvalidArgumentException('Color template not found.');
            }
            if (!$process->isSuccessful() || ($result['status'] ?? '') !== 'ok' || !is_array($result['summary'] ?? null)) {
                $diagnostic = $result['diagnostic'] ?? null;
                if (is_string($diagnostic) && preg_match('/\A[A-Za-z0-9_\\\\]+ in [A-Za-z0-9_.-]+:[0-9]+(?: \((?:missing [A-Za-z0-9_\\\\]+|database error [0-9]+)\))?\z/D', $diagnostic)) {
                    error_log('Color template sync worker failed: ' . $diagnostic);
                }
                throw new \RuntimeException('Color template synchronization could not be confirmed.');
            }
            $status = AuditEvent::SUCCEEDED;
            $summary = $result['summary'];
            return $summary;
        } catch (\Throwable $error) {
            if ($status === AuditEvent::FAILED && $error instanceof \InvalidArgumentException) {
                $status = AuditEvent::DENIED;
            }
            throw $error;
        } finally {
            try {
                $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, 'color.template.sync', 'color_template', (string) $templateId, AuditEvent::ALLOWED, $status));
            } catch (\Throwable) {
            }
        }
    }
}
