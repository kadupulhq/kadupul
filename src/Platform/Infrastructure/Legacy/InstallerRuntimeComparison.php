<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/** An explicit bounded observation, never an installer reservation or mutation.
 * @phpstan-import-type RuntimeSnapshot from InstallerRuntimeSnapshot
 */
final class InstallerRuntimeComparison
{
    public function __construct(
        private readonly string $projectDir,
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private readonly string $secret
    ) {}

    /** @return list<array{name: string, ok: bool, status: string, observed: string, remedy: string, value: string}> */
    public function compare(string $php): array
    {
        if (strlen($this->secret) < 32) {
            return [$this->check('Shared application secret', false, 'action_required', 'A strong web application secret is required before comparison.')];
        }
        $process = null;
        try {
            // Only the configured absolute interpreter and this fixed owned
            // entrypoint are executable. No request-supplied script or flags.
            if (str_contains($php, "\0") || !(str_starts_with($php, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $php) === 1)) {
                throw new \RuntimeException();
            }
            $executable = realpath($php);
            $entrypoint = $this->projectDir . '/bin/installer-runtime.php';
            $script = realpath($entrypoint);
            $root = realpath($this->projectDir);
            $mode = $executable === false ? false : @fileperms($executable);
            if ($executable === false || !is_file($executable) || !is_executable($executable)
                || $mode === false || ($mode & 06000) !== 0
                || $root === false || $script === false || is_link($entrypoint)
                || $script !== $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'installer-runtime.php' || !is_readable($script)) {
                throw new \RuntimeException();
            }
            $nonce = bin2hex(random_bytes(32));
            $web = InstallerRuntimeSnapshot::capture($this->projectDir);
            // exec_background inherits the process environment, not Symfony's
            // $_ENV overlay. In particular, never inject the web secret to make
            // a mismatched/missing worker secret appear consistent.
            $environment = getenv(null, true);
            foreach (array_keys($_ENV) as $name) {
                if (!array_key_exists($name, $environment)) {
                    $environment[$name] = false;
                }
            }
            $environment['APP_SECRET'] = getenv('APP_SECRET', true);
            $process = new Process(
                [$executable, '-d', 'auto_prepend_file=', '-d', 'auto_append_file=',
                    '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'zend.exception_ignore_args=1', $script],
                $this->projectDir,
                $environment,
                json_encode(['nonce' => $nonce], JSON_THROW_ON_ERROR),
                5
            );
            $stdout = '';
            $stderrBytes = 0;
            $process->run(static function (string $type, string $chunk) use (&$stdout, &$stderrBytes): void {
                if ($type === Process::OUT) {
                    if (strlen($stdout) + strlen($chunk) > 32768) {
                        throw new \RuntimeException();
                    }
                    $stdout .= $chunk;
                } else {
                    $stderrBytes += strlen($chunk);
                    if ($stderrBytes > 4096) {
                        throw new \RuntimeException();
                    }
                }
            });
            $receipt = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
            if (!$process->isSuccessful() || $stderrBytes !== 0 || !is_array($receipt) || ($receipt['protocol'] ?? null) !== 1
                || !is_array($receipt['snapshot'] ?? null) || !array_key_exists('proof', $receipt)) {
                throw new \RuntimeException();
            }
            return $this->assess($web, $receipt['snapshot'], $receipt['proof'], $nonce);
        } catch (\Throwable) {
            return [$this->check('Runtime comparison', false, 'not_assessed', 'The configured worker runtime did not confirm a bounded comparison.')];
        } finally {
            if ($process !== null) {
                try {
                    if ($process->isRunning()) {
                        $process->stop(0);
                    }
                } catch (\Throwable) {
                    // Never publish process diagnostics or private pipe data.
                }
            }
        }
    }

    /** @param array<string, mixed> $web
     *  @phpstan-param RuntimeSnapshot $web
     *  @param array<array-key, mixed> $worker
     *  @return list<array{name: string, ok: bool, status: string, observed: string, remedy: string, value: string}>
     */
    private function assess(#[\SensitiveParameter] array $web, #[\SensitiveParameter] array $worker, mixed $proof, string $nonce): array
    {
        $named = [];
        foreach ($worker as $name => $value) {
            if (!is_string($name)) {
                throw new \RuntimeException();
            }
            $named[$name] = $value;
        }
        $worker = $named;
        // Shape equality prevents unexpected or missing snapshot fields from
        // becoming silent agreement. Private evidence is never returned.
        if (array_keys($worker) !== array_keys($web) || !is_string($worker['php'])
            || strlen($worker['php']) > 64
            || preg_match('/\A([0-9]{1,2})\.([0-9]{1,2})\.([0-9]{1,3})(?:[-+][a-zA-Z0-9._-]+)?\z/D', $worker['php'], $version) !== 1
            || !is_int($worker['version_id']) || !is_array($worker['extensions']) || !is_array($worker['directories'])
            || array_keys($worker['extensions']) !== array_keys($web['extensions']) || array_keys($worker['directories']) !== array_keys($web['directories'])) {
            throw new \RuntimeException();
        }
        if ($worker['version_id'] !== (int) $version[1] * 10000 + (int) $version[2] * 100 + (int) $version[3]) {
            throw new \RuntimeException();
        }
        foreach (['uid', 'gid'] as $field) {
            if ($worker[$field] !== null && (!is_int($worker[$field]) || $worker[$field] < 0)) {
                throw new \RuntimeException();
            }
        }
        $workerExtensions = [];
        foreach ($worker['extensions'] as $name => $version) {
            if (!is_string($name)) {
                throw new \RuntimeException();
            }
            if ($version !== null && (!is_string($version) || strlen($version) > 64 || preg_match('/\A[a-zA-Z0-9._+-]+\z/D', $version) !== 1)) {
                throw new \RuntimeException();
            }
            $workerExtensions[$name] = $version;
        }
        foreach ($web['extensions'] as $version) {
            if ($version !== null && (strlen($version) > 64 || preg_match('/\A[a-zA-Z0-9._+-]+\z/D', $version) !== 1)) {
                throw new \RuntimeException();
            }
        }
        $worker['extensions'] = $workerExtensions;
        foreach ($worker['directories'] as $directory) {
            if (!is_array($directory) || array_keys($directory) !== ['present', 'readable', 'writable', 'traversable', 'location']
                || !is_bool($directory['present']) || !is_bool($directory['readable']) || !is_bool($directory['writable'])
                || ($directory['traversable'] !== null && !is_bool($directory['traversable']))
                || ($directory['location'] !== null && (!is_string($directory['location']) || strlen($directory['location']) > 4096))) {
                throw new \RuntimeException();
            }
        }
        $consistent = is_string($proof) && preg_match('/\A[a-f0-9]{64}\z/D', $proof) === 1
            && hash_equals(InstallerRuntimeSnapshot::proof($worker, $nonce, $this->secret) ?? '', $proof);
        if (!$consistent) {
            return [$this->check('Shared application secret', false, 'action_required', 'The inherited worker secret could not be confirmed against the web secret.')];
        }
        $supported = $web['version_id'] >= 80400 && $worker['version_id'] >= 80400;
        $extensions = !in_array(null, $web['extensions'], true) && !in_array(null, $worker['extensions'], true);
        $sameExtensions = $web['extensions'] === $worker['extensions'];
        $identityKnown = $web['uid'] !== null && $web['gid'] !== null && $worker['uid'] !== null && $worker['gid'] !== null;
        $sameIdentity = $web['uid'] === $worker['uid'] && $web['gid'] === $worker['gid'];
        $sameDirectories = $web['directories'] === $worker['directories'];
        $readable = true;
        $traversalKnown = true;
        $writable = true;
        foreach ($worker['directories'] as $directory) {
            $readable = $readable && $directory['present'] && $directory['readable'] && $directory['traversable'] !== false;
            $traversalKnown = $traversalKnown && $directory['traversable'] !== null;
            $writable = $writable && $directory['writable'];
        }
        $versions = [];
        foreach ($worker['extensions'] as $extension => $version) {
            if ($version === null || ($web['extensions'][$extension] ?? null) !== $version) {
                $versions[] = $extension . ': ' . ($web['extensions'][$extension] ?? 'missing') . ' / ' . ($version ?? 'missing');
            }
        }
        return [
            $this->check(
                'PHP runtime comparison',
                $supported,
                !$supported ? 'action_required' : ($web['php'] === $worker['php'] ? 'verified' : 'advisory'),
                'Both runtimes must support PHP 8.4; differing patch versions require review.',
                $web['php'] . ' / ' . $worker['php']
            ),
            $this->check(
                'Required extension comparison',
                $extensions,
                !$extensions ? 'action_required' : ($sameExtensions ? 'verified' : 'advisory'),
                'Required extension availability is checked; differing versions require review.',
                implode('; ', $versions)
            ),
            $this->check(
                'Process identity comparison',
                true,
                !$identityKnown ? 'not_assessed' : ($sameIdentity ? 'verified' : 'advisory'),
                !$identityKnown ? 'Effective process identity is unavailable on this platform.' : ($sameIdentity ? 'Effective process identities match.' : 'Effective process identities differ; separate service accounts may be intentional.')
            ),
            $this->check(
                'Directory access comparison',
                $readable,
                !$readable ? 'action_required' : ($sameDirectories && $writable ? 'verified' : 'advisory'),
                $sameDirectories ? 'Read-only directory permission observations match.' : 'Directory locations or permission observations differ; review the service-account roles.'
            ),
            $this->check(
                'Worker directory read and traversal',
                $readable,
                !$readable ? 'action_required' : ($traversalKnown ? 'verified' : 'not_assessed'),
                'Required worker directories must exist and allow reading and traversal. No writes were tested.'
            ),
            $this->check('Shared application secret', true, 'verified', 'The inherited worker secret matches the web secret for this private challenge.'),
        ];
    }

    /** @return array{name: string, ok: bool, status: string, observed: string, remedy: string, value: string} */
    private function check(string $name, bool $ok, string $status, string $observed, string $value = ''): array
    {
        return ['name' => $name, 'ok' => $ok, 'status' => $status, 'observed' => $observed, 'value' => $value,
            'remedy' => 'Review the configured PHP interpreter, required extensions, inherited secret and service-account directory permissions. No writes or installer startup were exercised.'];
    }
}
