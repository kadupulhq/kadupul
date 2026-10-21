<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\BrowserSession;
use Kadupul\Platform\Contract\DatabaseConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Process\Process;

/** Isolates procedural browser extensions from the Symfony request runtime. */
final readonly class LegacyBrowserWorker
{
    public function __construct(
        private string $projectDir,
        private RequestStack $requests,
        private BrowserSession $session,
        private DatabaseConnection $database,
        private InstallationConfiguration $configuration,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string, mixed> */
    public function console(int $actorId): array
    {
        return $this->run('legacy-console-home.php', ['index_path' => $this->configuration->urlPath() . '/index.php'], $actorId);
    }

    /** @return array<string, mixed> */
    public function permissionDenied(int $actorId): array
    {
        return $this->run('legacy-permission-denied.php', ['page_path' => $this->configuration->urlPath() . '/permission_denied.php'], $actorId);
    }

    /**
     * @param array<string, string> $entry the native script path the worker presents to include/auth.php
     * @return array<string, mixed>
     */
    private function run(string $script, array $entry, int $actorId): array
    {
        $request = $this->requests->getCurrentRequest();
        $snapshot = $this->session->read();
        if ($request === null || $request->getMethod() !== 'GET' && $request->getMethod() !== 'HEAD'
            || (int) ($snapshot['sess_user_id'] ?? 0) !== $actorId
            || !is_string($snapshot['sess_user_credential'] ?? null)) {
            throw new \RuntimeException('Console compatibility requires the admitted native session.');
        }
        $configuration = $this->configuration->values();
        // Native auth.php checks the collector's local schema even while its
        // online browser reads use the primary. Preserve its install redirect
        // before starting a CLI worker, where header() cannot carry a redirect.
        $schema = $configuration['console_schema_version'] ?? $this->database->get()->query('SELECT cacti FROM version LIMIT 1')->fetchColumn();
        $version = file_get_contents($this->projectDir . '/include/cacti_version');
        if (!is_string($schema) || $schema === '' || $version === false) {
            throw new \RuntimeException('The installation version is unavailable.');
        }
        if ($schema !== trim($version)) {
            throw new ConsoleUpgradeRequired($this->configuration->urlPath() . '/install/');
        }
        $configured = $this->database->get()->query("SELECT value FROM settings WHERE name = 'path_php_binary'")->fetchColumn();
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $binary = realpath($binary);
        if ($binary === false || !is_file($binary) || !is_executable($binary)) {
            throw new \RuntimeException('The configured PHP interpreter is unavailable.');
        }
        // Native cookie ownership has already been checked by SharedSession.
        // Credentials travel only over the private worker pipe, never argv/logs.
        $command = [
            'actor' => $actorId,
            'credential' => $snapshot['sess_user_credential'],
            'session_id' => session_id(),
            'session_path' => session_save_path(),
            ...$entry,
            'server' => array_intersect_key($request->server->all(), array_flip([
                'HTTPS', 'HTTP_HOST', 'SERVER_NAME', 'SERVER_PORT', 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'HTTP_ACCEPT_LANGUAGE',
                'HTTP_ORIGIN', 'HTTP_REFERER', 'HTTP_X_REQUESTED_WITH', 'QUERY_STRING',
            ])),
            'cookies' => $request->cookies->all(),
            'query' => $request->query->all(),
        ];
        try {
            $input = json_encode($command, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new BadRequestHttpException('Invalid console request.', $error);
        }
        if (strlen($input) > 65536) {
            throw new BadRequestHttpException('Console request is too large.');
        }
        $process = new Process([$binary, $this->projectDir . '/bin/' . $script], $this->projectDir, null, $input, 60);
        $bytes = 0;
        try {
            $process->run(static function (string $type, string $chunk) use (&$bytes): void {
                $bytes += strlen($chunk);
                if ($bytes > 8388608) {
                    throw new \RuntimeException('Console extension output exceeds its limit.');
                }
            });
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        if (!$process->isSuccessful()) {
            $this->recordFailure($process->getErrorOutput());
            throw new \RuntimeException('Console compatibility could not be confirmed.');
        }
        try {
            $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Console compatibility returned an invalid response.');
        }
        if (!is_array($result) || ($result['status'] ?? null) !== 'ok' || ($result['actor'] ?? null) !== $actorId) {
            throw new \RuntimeException('Console compatibility could not be confirmed.');
        }

        return $result;
    }

    private function recordFailure(string $stderr): void
    {
        $diagnostic = json_decode($stderr, true, 8);
        $context = ['phase' => 'unknown', 'class' => 'unknown', 'file' => 'unknown', 'line' => 0];
        if (is_array($diagnostic) && in_array($diagnostic['phase'] ?? null, ['handoff', 'bootstrap', 'identity', 'extensions', 'session'], true)
            && is_string($diagnostic['class'] ?? null) && preg_match('/\\A[a-zA-Z0-9_\\\\]{1,200}\\z/D', $diagnostic['class']) === 1
            && is_string($diagnostic['file'] ?? null) && preg_match('/\\A[a-zA-Z0-9_.-]{1,100}\\z/D', $diagnostic['file']) === 1
            && is_int($diagnostic['line'] ?? null) && $diagnostic['line'] > 0 && $diagnostic['line'] < 1000000) {
            $context = array_intersect_key($diagnostic, array_flip(['phase', 'class', 'file', 'line']));
        }
        $this->logger->error('Console compatibility worker failed. phase={phase} class={class} source={file}:{line}', $context);
    }
}
