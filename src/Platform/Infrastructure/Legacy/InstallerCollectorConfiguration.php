<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\Filesystem\Filesystem;

/** Register the collector through the explicit remote connection and replace its local configuration atomically. */
final readonly class InstallerCollectorConfiguration
{
    /** Credentials stay in caller-owned configuration and never enter diagnostics. */
    public function __construct(private string $projectDir, private NativeInstallerFunctions $native, private Filesystem $files = new Filesystem()) {}

    /**
     * A remote registration is not undone by a later local-file failure; retries reuse its hostname.
     * @param list<mixed> $remote Native connection arguments in driver order.
     * @param array<string, mixed> $local Native collector database registration fields.
     */
    public function update(#[\SensitiveParameter] array $remote, #[\SensitiveParameter] array $local): string
    {
        $path = $this->projectDir . '/include/config.php';
        $stat = @stat($path);
        if (is_link($path) || $stat === false || $stat['nlink'] !== 1 || !is_file($path) || !is_writable($path)) {
            return 'Configuration file is not writable';
        }
        $original = @file_get_contents($path);
        if ($original === false || $original === '') {
            return 'Failed to read configuration file';
        }
        $connection = $this->native->call('db_connect_real', ...$remote);
        if (!is_object($connection)) {
            return 'Failed to connect database';
        }
        try {
            $hostname = $this->native->hostname();
            if ($hostname === false) {
                $hostname = php_uname('n');
            }
            // The native scalar reader's fifth argument selects the actual remote session.
            $id = $this->native->call('db_fetch_cell_prepared', 'SELECT id FROM poller WHERE hostname = ?', [$hostname], '', true, $connection);
            if ((bool) $this->native->call('db_error')) {
                return 'Unable to obtain poller id for this server';
            }
            if (!(bool) $id) {
                if (!function_exists('__')) {
                    throw new \RuntimeException('The authenticated native installer runtime is required.');
                }
                $id = $this->native->call('sql_save', ['name' => \__('New Poller'), 'hostname' => $hostname] + $local, 'poller', 'id', true, $connection);
            }
            if ((!is_int($id) && !is_string($id)) || preg_match('/\A[1-9][0-9]{0,4}\z/D', (string) $id) !== 1 || (int) $id > 65535) {
                return 'Unable to obtain poller id for this server';
            }
            try {
                $updated = $this->withPollerId($original, (int) $id);
                if (!$this->unchanged($path, $original)) {
                    return 'Configuration file changed during installation';
                }
                $this->files->dumpFile($path, $updated);
                if (@file_get_contents($path) !== $updated) {
                    return 'Failed to confirm configuration file';
                }
            } catch (\Throwable) {
                return 'Failed to update configuration file';
            }
            return '';
        } finally {
            $this->native->call('db_close', $connection);
        }
    }

    /** Recheck path identity at the local mutation boundary after remote registration. */
    private function unchanged(string $path, string $expected): bool
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        return !is_link($path) && $stat !== false && $stat['nlink'] === 1 && @file_get_contents($path) === $expected;
    }

    /** Replace the assignment value without rewriting comments, strings or adjacent settings. */
    private function withPollerId(string $source, int $id): string
    {
        $tokens = token_get_all($source, TOKEN_PARSE);
        $offsets = [];
        $offset = 0;
        foreach ($tokens as $token) {
            $offsets[] = $offset;
            $offset += strlen(is_array($token) ? $token[1] : $token);
        }
        $changes = [];
        $scope = 0;
        foreach ($tokens as $index => $token) {
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                ++$scope;
            } elseif ($token === '}') {
                --$scope;
            }
            if ($scope !== 0 || !is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$poller_id') {
                continue;
            }
            $previous = $index - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                --$previous;
            }
            $prior = $tokens[$previous] ?? null;
            if ($prior !== ';' && $prior !== '}' && !(is_array($prior) && $prior[0] === T_OPEN_TAG)) {
                continue;
            }
            $cursor = $index + 1;
            while (isset($tokens[$cursor]) && is_array($tokens[$cursor]) && in_array($tokens[$cursor][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                ++$cursor;
            }
            if (($tokens[$cursor] ?? null) !== '=') {
                continue;
            }
            $start = $offsets[$cursor] + 1;
            $depth = 0;
            for (++$cursor; isset($tokens[$cursor]); ++$cursor) {
                $part = $tokens[$cursor];
                if ($part === ';' && $depth === 0) {
                    $changes[] = [$start, $offsets[$cursor] - $start];
                    break;
                }
                if (in_array($part, ['(', '[', '{'], true) || (is_array($part) && in_array($part[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    ++$depth;
                } elseif (in_array($part, [')', ']', '}'], true)) {
                    --$depth;
                }
            }
        }
        if (count($changes) !== 1) {
            throw new \RuntimeException('A single collector identity assignment is required.');
        }
        [$start, $length] = $changes[0];
        $result = substr_replace($source, ' ' . $id, $start, $length);
        $validated = token_get_all($result, TOKEN_PARSE);
        return implode('', array_map(static fn(array|string $token): string => is_array($token) ? $token[1] : $token, $validated));
    }
}
