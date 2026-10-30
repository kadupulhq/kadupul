<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\BrowserSession;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\BrowserWorkerMode;
use Kadupul\Platform\Contract\BrowserWorkerRunner;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Process\Process;

/** Private-pipe transport for authenticated native browser workers. */
#[AsAlias(BrowserWorkerRunner::class)]
final readonly class LegacyBrowserWorkerRunner implements BrowserWorkerRunner
{
    public function __construct(
        private string $projectDir,
        private RequestStack $requests,
        private BrowserSession $session,
        private DatabaseConnection $database,
        private InstallationConfiguration $configuration,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $entry the native script path the worker presents to include/auth.php, and its operation input
     * @param float $timeout seconds before the worker is stopped
     * @param bool $forwardRequest false hands over only what resumes the
     *     session, so a cached result cannot vary on client-chosen query
     *     strings, cookies or referrers
     * @return array<string, mixed>
     */
    public function run(string $script, #[\SensitiveParameter] array $entry, int $actorId, float $timeout = 60, bool $forwardRequest = true, BrowserWorkerMode $mode = BrowserWorkerMode::Read): array
    {
        if (!is_finite($timeout) || $timeout <= 0 || preg_match('/\Alegacy-[a-z-]+\.php\z/D', $script) !== 1
            || array_intersect(array_keys($entry), ['actor', 'credential', 'session_id', 'session_path', 'server', 'cookies', 'query']) !== []) {
            throw new \InvalidArgumentException('Invalid browser worker command.');
        }
        $request = $this->requests->getCurrentRequest();
        $method = $request?->getMethod();
        $allowed = $mode === BrowserWorkerMode::Mutation ? $method === 'POST' : $method === 'GET' || $method === 'HEAD';
        $snapshot = $this->session->read();
        $sessionActor = $snapshot['sess_user_id'] ?? null;
        $credential = $snapshot['sess_user_credential'] ?? null;
        if ($request === null || !$allowed || $actorId < 1
            || ($sessionActor !== $actorId && $sessionActor !== (string) $actorId)
            || !is_string($credential) || preg_match('/\A[a-f0-9]{64}\z/D', $credential) !== 1) {
            throw new \RuntimeException('Console compatibility requires the admitted native session.');
        }
        $configuration = $this->configuration->values();
        // Native auth.php checks the collector's local schema even while its
        // online browser reads use the primary. Preserve its install redirect
        // before starting a CLI worker, where header() cannot carry a redirect.
        $schema = $configuration['console_schema_version'] ?? $this->setting('SELECT cacti FROM version LIMIT 1');
        $version = file_get_contents($this->projectDir . '/include/cacti_version');
        if (!is_string($schema) || $schema === '' || $version === false) {
            throw new \RuntimeException('The installation version is unavailable.');
        }
        if ($schema !== trim($version)) {
            throw new ConsoleUpgradeRequired($this->configuration->urlPath() . '/install/');
        }
        $configured = $this->setting("SELECT value FROM settings WHERE name = 'path_php_binary'");
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $binary = realpath($binary);
        if ($binary === false || !is_file($binary) || !is_executable($binary)) {
            throw new \RuntimeException('The configured PHP interpreter is unavailable.');
        }
        $worker = realpath($this->projectDir . '/bin/' . $script);
        $directory = realpath($this->projectDir . '/bin');
        if ($worker === false || $directory === false || dirname($worker) !== $directory || !is_file($worker)) {
            throw new \RuntimeException('The browser worker is unavailable.');
        }
        $cookies = $request->cookies->all();
        if (!$forwardRequest) {
            $sessionName = $configuration['session_name'] ?? null;
            if (!is_string($sessionName) || $sessionName === '') {
                throw new \RuntimeException('The console session cookie name is unavailable.');
            }
            $cookies = array_intersect_key($cookies, [$sessionName => true]);
        }
        // Native cookie ownership has already been checked by SharedSession.
        // Credentials travel only over the private worker pipe, never argv/logs.
        $command = [
            'actor' => $actorId,
            'credential' => $credential,
            'session_id' => session_id(),
            'session_path' => session_save_path(),
            ...$entry,
            'server' => array_intersect_key($request->server->all(), array_flip([
                'HTTPS', 'HTTP_HOST', 'SERVER_NAME', 'SERVER_PORT', 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'HTTP_ACCEPT_LANGUAGE',
                ...($forwardRequest ? ['HTTP_ORIGIN', 'HTTP_REFERER', 'HTTP_X_REQUESTED_WITH', 'QUERY_STRING'] : []),
            ])),
            'cookies' => $cookies,
            'query' => $forwardRequest ? $request->query->all() : [],
        ];
        try {
            $input = json_encode($command, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new BadRequestHttpException('Invalid console request.', $error);
        }
        if (strlen($input) > $mode->inputLimit()) {
            throw new BadRequestHttpException('Console request is too large.');
        }
        $process = new Process([$binary, $worker], $this->projectDir, null, $input, $timeout);
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

        $response = [];
        foreach ($result as $key => $value) {
            if (!is_string($key)) {
                throw new \RuntimeException('Console compatibility returned an invalid response.');
            }
            $response[$key] = $value;
        }

        return $response;
    }

    private function setting(string $query): mixed
    {
        $statement = $this->database->get()->query($query);
        if ($statement === false) {
            throw new \RuntimeException('The installation configuration is unavailable.');
        }

        return $statement->fetchColumn();
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
