<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

use Kadupul\Graphing\Application\Port\ColorTemplateSynchronization;
use Kadupul\Graphing\Application\Query\ColorTemplateAccessDenied;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Process\Process;

/**
 * push_out_aggregates() needs the legacy bootstrap, so one worker process
 * per template runs it inside a single transaction and reports one line.
 */
#[AsAlias(ColorTemplateSynchronization::class)]
final readonly class LegacyColorTemplateSynchronization implements ColorTemplateSynchronization
{
    public const string MARKER = 'KADUPUL_COLOR_SYNC_RESULT';

    public function __construct(
        private DatabaseConnection $database,
        private AuditTrail $audit,
        private string $projectDir,
        private float $timeout = 120,
    ) {}

    public function synchronize(int $actorId, int $templateId, string $revision): array
    {
        $decision = AuditEvent::ALLOWED;
        $outcome = AuditEvent::FAILED;
        try {
            $process = new Process([$this->binary(), $this->projectDir . '/bin/legacy-color-template-sync.php'], $this->projectDir);
            $process->setTimeout($this->timeout);
            $process->setInput(json_encode(['actor' => $actorId, 'template_id' => $templateId, 'revision' => $revision], JSON_THROW_ON_ERROR));
            $process->run();
            if (preg_match_all('/^' . self::MARKER . '=(\{[^\r\n]+\})$/m', $process->getOutput(), $matches) !== 1) {
                throw new \RuntimeException('Color Template synchronization outcome is unknown.');
            }
            $result = json_decode($matches[1][0], true, 4, JSON_THROW_ON_ERROR);
            $status = is_array($result) ? ($result['status'] ?? null) : null;
            if ($status === 'denied') {
                $decision = $outcome = AuditEvent::DENIED;
                throw new ColorTemplateAccessDenied(false);
            }
            if ($status === 'changed') {
                $decision = $outcome = AuditEvent::DENIED;
                throw new \InvalidArgumentException(LegacyColorTemplateStore::CHANGED);
            }
            $summary = $result['summary'] ?? null;
            if (!$process->isSuccessful() || $status !== 'ok' || !is_array($summary) || count($summary) !== 3
                || !is_string($summary['template'] ?? null)
                || !is_int($summary['aggregate_templates'] ?? null) || $summary['aggregate_templates'] < 0
                || !is_int($summary['aggregate_graphs'] ?? null) || $summary['aggregate_graphs'] < 0) {
                throw new \RuntimeException('Color Template synchronization was not confirmed.');
            }
            $outcome = AuditEvent::SUCCEEDED;
            return ['template' => $summary['template'], 'aggregate_templates' => $summary['aggregate_templates'], 'aggregate_graphs' => $summary['aggregate_graphs']];
        } catch (\JsonException $error) {
            throw new \RuntimeException('Color Template synchronization outcome is unknown.', 0, $error);
        } finally {
            try {
                $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actorId, 'graphing.color_template.sync', 'color_template', (string) $templateId, $decision, $outcome));
            } catch (\Throwable) {
                // Audit failure does not change the worker's confirmed outcome.
            }
        }
    }

    /**
     * The worker's last line. It runs after the commit, so it must not fail:
     * a stored name that is not valid UTF-8 is reported with U+FFFD.
     *
     * @param ?array{template: string, aggregate_templates: int, aggregate_graphs: int} $summary
     */
    public static function result(string $status, ?array $summary): string
    {
        return self::MARKER . '=' . json_encode(['status' => $status, 'summary' => $summary], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function binary(): string
    {
        // A web SAPI's PHP_BINARY names the server, so use the configured CLI
        // interpreter as the other legacy workers do.
        $configured = ColorTemplateSql::column(ColorTemplateSql::execute($this->database->get(), "SELECT value FROM settings WHERE name = 'path_php_binary'"));
        return is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
    }
}
