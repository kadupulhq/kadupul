<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Infrastructure\Symfony;

use Kadupul\AssetManagement\Domain\AssetData;
use Doctrine\DBAL\Connection;
use Kadupul\AssetManagement\Application\Port\AssetWriter;
use Kadupul\AssetManagement\Domain\AssetError;
use Kadupul\IdentityAccess\Contract\BrowserSession;
use Symfony\Component\Process\Process;

/**
 * Applies a register command for a web request by running the console command in a separate PHP process, authenticated with the visitor's session.
 * The request_id must be echoed back in the result.
 *
 * @phpstan-import-type Result from AssetData
 */
final readonly class AssetWorker implements AssetWriter
{
    /** Worker stdout and stderr together; a larger reply is not a receipt. */
    private const int OUTPUT_LIMIT = 8388608;

    /**
     * Binds the worker to access checks, the browser session, the database and the project directory.
     */
    public function __construct(private WebAssetAccess $access, private BrowserSession $session, private Connection $database, private string $projectDir) {}

    /**
     * Runs the command in the worker process and returns its result.
     *
     * @param array<string, mixed> $command
     * @phpstan-return Result
     * @throws AssetError with reason unauthenticated, unavailable or invalid_command when the request cannot be sent, uncertain when the worker's outcome cannot be established because the command may still have been applied, or the worker's own reason code when it refuses the command
     */
    public function apply(array $command): array
    {
        $actor = $this->access->requireActor();
        // The worker rechecks this account by id and session credential, so a
        // password change or logout elsewhere refuses a mutation already sent.
        $snapshot = $this->session->read();
        $sessionActor = $snapshot['sess_user_id'] ?? null;
        $credential = $snapshot['sess_user_credential'] ?? null;
        if (($sessionActor !== $actor->id && $sessionActor !== (string) $actor->id)
            || !is_string($credential) || preg_match('/\A[a-f0-9]{64}\z/D', $credential) !== 1) {
            throw new AssetError('unauthenticated');
        }
        $configured = $this->database->fetchOne("SELECT value FROM settings WHERE name = 'path_php_binary'");
        $binary = is_string($configured) && trim($configured) !== '' ? trim($configured) : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php');
        $binary = realpath($binary);
        if ($binary === false || !is_file($binary) || !is_executable($binary)) {
            throw new AssetError('unavailable');
        }
        $payload = json_encode(['actor_id' => $actor->id, 'credential' => $credential, 'command' => $command], JSON_THROW_ON_ERROR);
        if (strlen($payload) > 524288) {
            throw new AssetError('invalid_command');
        }
        // Under Apache's PHP module Process's default environment keeps only
        // variables that also appear in $_SERVER, which drops the process
        // environment (feature flag, APP_SECRET). Pass that environment so the
        // worker evaluates its own gate exactly as any other command would.
        $process = new Process([$binary, $this->projectDir . '/bin/console', 'assets:register', '--worker', '--no-interaction', '--no-ansi'], $this->projectDir, getenv(null, true), $payload, 60);
        $bytes = 0;
        try {
            $process->run(static function (string $type, string $chunk) use (&$bytes): void {
                $bytes += strlen($chunk);
                if ($bytes > self::OUTPUT_LIMIT) {
                    throw new \RuntimeException('Asset worker output exceeds its limit.');
                }
            });
            $result = json_decode(trim($process->getOutput()), true, 20, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new AssetError('uncertain');
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        if (!is_array($result)) {
            throw new AssetError('uncertain');
        }
        if (!$process->isSuccessful() || ($result['status'] ?? null) !== 'ok') {
            throw new AssetError(is_string($result['reason'] ?? null) ? $result['reason'] : 'uncertain');
        }
        if (!is_array($result['result'] ?? null) || ($result['result']['request_id'] ?? null) !== ($command['request_id'] ?? null)) {
            throw new AssetError('uncertain');
        }
        return AssetData::result(AssetData::object($result['result']));
    }
}
