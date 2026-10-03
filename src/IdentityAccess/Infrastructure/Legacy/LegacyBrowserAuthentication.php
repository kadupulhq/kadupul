<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Restore credentials only for the About-specific authenticated-access port. */
final readonly class LegacyBrowserAuthentication
{
    private const array PROXY_HEADERS = ['X-Forwarded-For', 'X-Client-IP', 'X-Real-IP', 'X-ProxyUser-Ip', 'CF-Connecting-IP', 'True-Client-IP', 'HTTP_X_FORWARDED', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

    public function __construct(private RequestStack $requests, private DatabaseConnection $database, private LegacyConfiguration $configuration, private NativeAuthenticationSession $sessions, private AuditTrail $audit) {}

    public function existingActor(int $id, mixed $credential): ?Actor
    {
        $database = $this->database->get();
        if ($id <= 0 || !is_string($credential) || preg_match('/\A[a-f0-9]{64}\z/D', $credential) !== 1 || !in_array($this->method($database), [1, 2, 3, 4], true)) {
            return null;
        }
        $request = $this->requests->getCurrentRequest();
        if ($this->setting($database, 'force_https') === 'on' && ($request === null || !$request->isSecure() || !Request::createFromGlobals()->isSecure())) {
            throw new AccessDeniedHttpException('HTTPS is required.');
        }
        $user = BrowserAuthenticationSql::row(BrowserAuthenticationSql::execute($database, 'SELECT id, username, enabled, locked, password FROM user_auth WHERE id = ?', [$id]));
        if (!$this->eligible($database, $user)) {
            return null;
        }
        require_once dirname(__DIR__, 4) . '/lib/auth.php';
        return hash_equals(\auth_session_credential_generation($id, $user['password'], $database), $credential)
            ? new Actor($id, $user['username']) : null;
    }

    public function restore(): ?Actor
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null || !in_array($request->attributes->get('_route'), ['platform_about', 'platform_about_legacy'], true)) {
            return null;
        }
        $basic = $this->nativeBasic($request);
        $remember = $request->cookies->get('cacti_remembers');
        if (!is_string($remember) || ($_COOKIE['cacti_remembers'] ?? null) !== $remember) {
            $remember = null;
        }
        if ($basic === null && $remember === null) {
            return null;
        }
        $database = $this->database->get();
        if ($database->inTransaction()) {
            throw new \RuntimeException('Browser authentication cannot own an existing transaction.');
        }
        $config = $this->configuration->values();
        $tables = ['settings', 'user_auth', 'settings_user', 'user_log'];
        if ($config['database_sessions']) {
            $tables[] = 'sessions';
        }
        $this->requireEngines($database, $tables);
        if ($database->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' && $database->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false) {
            throw new \RuntimeException('Browser authentication isolation could not be established.');
        }
        if ($database->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            BrowserAuthenticationSql::confirmConnection($database);
        }
        if (!$database->beginTransaction()) {
            throw new \RuntimeException('Browser authentication transaction could not start.');
        }
        $session = null;
        $replacement = null;
        $actor = null;
        $reason = 'basic_restore';
        try {
            BrowserAuthenticationSql::confirmConnection($database);
            $force = $this->setting($database, 'force_https');
            if ($force === 'on' && (!$request->isSecure() || !Request::createFromGlobals()->isSecure())) {
                throw new AccessDeniedHttpException('HTTPS is required.');
            }
            $method = $this->method($database);
            $ip = $this->nativeIp($request, $config);
            if ($ip === null || !in_array($method, [1, 2, 3, 4], true)) {
                $this->rollback($database);
                return null;
            }
            if ($method === 2) {
                $username = $basic === null ? null : $this->mappedBasic($database, $basic);
                $user = $username === null ? false : BrowserAuthenticationSql::row(BrowserAuthenticationSql::execute($database, 'SELECT id, username, realm, enabled, locked, password FROM user_auth WHERE realm = 2 AND username = ?' . $this->lock($database), [$username]));
            } else {
                $reason = 'cookie_restore';
                if ($remember === null || $this->setting($database, 'auth_cache_enabled') !== 'on') {
                    $this->rollback($database);
                    return null;
                }
                $this->requireEngines($database, ['user_auth_cache']);
                $credential = $this->remembered($database, $remember, $ip);
                $user = $credential['user'] ?? false;
            }
            if (!$this->eligible($database, $user)) {
                $this->rollback($database);
                return null;
            }
            $id = (int) $user['id'];
            require_once dirname(__DIR__, 4) . '/lib/auth.php';
            $generation = \auth_session_credential_generation($id, $user['password'], $database);
            $actor = new Actor($id, $user['username']);
            if ($reason === 'cookie_restore') {
                $removed = BrowserAuthenticationSql::execute($database, 'DELETE FROM user_auth_cache WHERE id = ? AND user_id = ? AND hostname = ? AND token = ?', [$credential['cache'], $id, $ip, $credential['hash']]);
                if ($removed->rowCount() !== 1) {
                    throw new \RuntimeException('Remembered authentication token was not consumed.');
                }
                $replacement = bin2hex(random_bytes(32));
                $inserted = BrowserAuthenticationSql::execute($database, 'INSERT INTO user_auth_cache (user_id, hostname, last_update, token) VALUES (?, ?, CURRENT_TIMESTAMP, ?)', [$id, $ip, hash('sha512', $replacement)]);
                if ($inserted->rowCount() !== 1) {
                    throw new \RuntimeException('Remembered authentication token insertion was not confirmed.');
                }
            }
            $session = $this->sessions->establish($id, $request, $ip, $generation);
            $this->log($database, $user['username'], $id, $reason === 'basic_restore' ? 1 : 2, $ip);
            if (!$database->commit()) {
                throw new \RuntimeException('Browser authentication commit was not confirmed.');
            }
            BrowserAuthenticationSql::confirmConnection($database);
            $this->record($actor, $reason, AuditEvent::SUCCEEDED);
            $this->sessions->publish($session, $request);
            if ($replacement !== null && !setcookie('cacti_remembers', $id . ',' . (int) $user['realm'] . ',' . $replacement, ['expires' => time() + 86400 * 30, 'path' => $config['url_path'], 'domain' => $config['cookie_domain'], 'secure' => $request->isSecure(), 'httponly' => true, 'samesite' => 'Strict'])) {
                throw new \RuntimeException('Remembered authentication cookie could not be rotated.');
            }
            return $actor;
        } catch (\Throwable $failure) {
            $cleanup = null;
            // Attempt every credential cleanup even if rollback itself fails.
            if ($database->inTransaction()) {
                try {
                    $this->rollback($database);
                } catch (\Throwable $cleanupFailure) {
                    $cleanup = $cleanupFailure;
                }
            }
            if ($session !== null) {
                try {
                    $this->sessions->revoke($session, $request);
                } catch (\Throwable $cleanupFailure) {
                    $cleanup ??= $cleanupFailure;
                }
            }
            if ($replacement !== null) {
                try {
                    BrowserAuthenticationSql::execute($database, 'DELETE FROM user_auth_cache WHERE token = ?', [hash('sha512', $replacement)]);
                } catch (\Throwable $cleanupFailure) {
                    $cleanup ??= $cleanupFailure;
                }
                try {
                    if (!setcookie('cacti_remembers', '', ['expires' => time() - 3600, 'path' => $config['url_path'], 'domain' => $config['cookie_domain'], 'secure' => $request->isSecure(), 'httponly' => true, 'samesite' => 'Strict'])) {
                        throw new \RuntimeException('Failed authentication cookie cleanup.');
                    }
                } catch (\Throwable $cleanupFailure) {
                    $cleanup ??= $cleanupFailure;
                }
            }
            try {
                $this->record($actor, $reason, AuditEvent::FAILED);
            } catch (\Throwable $auditFailure) {
                if ($cleanup !== null) {
                    throw new \RuntimeException('Browser authentication cleanup was not confirmed.', 0, $cleanup);
                }
                throw new \RuntimeException('Browser authentication failure audit was not confirmed.', 0, $auditFailure);
            }
            if ($cleanup !== null) {
                throw new \RuntimeException('Browser authentication cleanup was not confirmed.', 0, $cleanup);
            }
            throw $failure;
        }
    }

    private function log(\PDO $database, string $username, int $id, int $result, string $ip): void
    {
        $time = BrowserAuthenticationSql::column(BrowserAuthenticationSql::execute($database, 'SELECT CURRENT_TIMESTAMP'));
        if (!is_string($time) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $time)) {
            throw new \RuntimeException('Authentication log timestamp was not confirmed.');
        }
        // Legacy logging deduplicates the same account within one second.
        $verb = $database->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $logged = BrowserAuthenticationSql::execute($database, $verb . ' INTO user_log (username, user_id, result, ip, time) VALUES (?, ?, ?, ?, ?)', [$username, $id, $result, $ip, $time]);
        if ($logged->rowCount() === 1) {
            return;
        }
        $existing = $logged->rowCount() === 0
            ? BrowserAuthenticationSql::column(BrowserAuthenticationSql::execute($database, 'SELECT 1 FROM user_log WHERE username = ? AND user_id = ? AND time = ?', [$username, $id, $time]))
            : false;
        if (!in_array($existing, [1, '1'], true)) {
            throw new \RuntimeException('Browser authentication log insertion was not confirmed.');
        }
    }

    private function remembered(\PDO $database, string $cookie, string $ip): ?array
    {
        $parts = explode(',', $cookie);
        if (count($parts) === 2) {
            [$identity, $token] = $parts;
            $realm = 0;
        } elseif (count($parts) === 3 && preg_match('/\A[0-9]+\z/D', $parts[1])) {
            [$identity, $realm, $token] = $parts;
            $realm = (int) $realm;
        } else {
            return null;
        }
        if ($identity === '' || $token === '' || strlen($token) > 512) {
            return null;
        }
        $field = ctype_digit($identity) ? 'id' : 'username';
        $user = BrowserAuthenticationSql::row(BrowserAuthenticationSql::execute($database, 'SELECT id, username, realm, enabled, locked, password, must_change_password, password_change FROM user_auth WHERE ' . $field . ' = ? AND realm = ?' . $this->lock($database), [$identity, $realm]));
        // The locked account must satisfy the same local forced-change policy
        // as legacy remembered authentication before any credential is consumed.
        if (!$this->eligible($database, $user) || ((int) $user['realm'] === 0 && $user['must_change_password'] === 'on' && $user['password_change'] === 'on')) {
            return null;
        }
        $hash = hash('sha512', $token);
        $cached = BrowserAuthenticationSql::row(BrowserAuthenticationSql::execute($database, 'SELECT id FROM user_auth_cache WHERE user_id = ? AND token = ? AND hostname = ?' . ($database->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''), [(int) $user['id'], $hash, $ip]));
        return $cached === false ? null : ['user' => $user, 'cache' => (int) $cached['id'], 'hash' => $hash];
    }

    private function eligible(\PDO $database, array|false $user): bool
    {
        if ($user === false || (int) $user['id'] <= 0 || $user['enabled'] !== 'on' || $user['locked'] === 'on') {
            return false;
        }
        $guest = $this->setting($database, 'guest_user');
        return (int) $user['id'] !== (int) $guest && $user['username'] !== $guest;
    }

    private function method(\PDO $database): int
    {
        $method = $this->setting($database, 'auth_method');
        return is_string($method) && ctype_digit($method) ? (int) $method : -1;
    }

    private function setting(\PDO $database, string $name): string|false
    {
        $value = BrowserAuthenticationSql::column(BrowserAuthenticationSql::execute($database, 'SELECT value FROM settings WHERE name = ?' . $this->lock($database), [$name]));
        return $value === false ? false : (string) $value;
    }

    private function lock(\PDO $database): string
    {
        return $database->inTransaction() && $database->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' LOCK IN SHARE MODE' : '';
    }

    private function rollback(\PDO $database): void
    {
        if (!$database->rollBack()) {
            throw new \RuntimeException('Browser authentication rollback was not confirmed.');
        }
        BrowserAuthenticationSql::confirmConnection($database);
    }

    private function requireEngines(\PDO $database, array $tables): void
    {
        $driver = $database->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return;
        }
        if ($driver !== 'mysql') {
            throw new \RuntimeException('Unsupported browser authentication database.');
        }
        foreach ($tables as $table) {
            $definition = BrowserAuthenticationSql::execute($database, 'SHOW CREATE TABLE `' . $table . '`');
            $create = BrowserAuthenticationSql::row($definition);
            if (!$definition->closeCursor() || $definition->errorCode() !== '00000') {
                throw new \RuntimeException('Browser authentication metadata close was not confirmed.');
            }
            if ($create === false || !is_string($create['Create Table'] ?? null)
                || preg_match('/\ACREATE TABLE\s/i', $create['Create Table']) !== 1) {
                throw new \RuntimeException('Browser authentication requires transactional tables.');
            }
            // SHOW CREATE can include engine-like text inside a column or table
            // comment. After rejecting temporary tables and views, inspect the
            // persistent target's native engine on this same mutation session.
            $status = BrowserAuthenticationSql::execute($database, 'SHOW TABLE STATUS WHERE Name = ?', [$table]);
            $metadata = BrowserAuthenticationSql::row($status);
            $extra = BrowserAuthenticationSql::row($status);
            if (!$status->closeCursor() || $status->errorCode() !== '00000') {
                throw new \RuntimeException('Browser authentication metadata close was not confirmed.');
            }
            if ($metadata === false || ($metadata['Name'] ?? null) !== $table
                || ($metadata['Engine'] ?? null) !== 'InnoDB' || $extra !== false) {
                throw new \RuntimeException('Browser authentication requires transactional tables.');
            }
        }
    }

    private function nativeBasic(Request $request): ?string
    {
        // PHP_AUTH_USER alone can come from an unverified Authorization header.
        // Only the web server's authenticated principal can restore identity.
        foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $name) {
            if (array_key_exists($name, $_SERVER)) {
                $value = $_SERVER[$name];
                if (!is_string($value) || $value === '' || $request->server->get($name) !== $value) {
                    return null;
                }
                if (array_key_exists('PHP_AUTH_USER', $_SERVER)) {
                    $basic = $_SERVER['PHP_AUTH_USER'];
                    if (!is_string($basic) || $basic !== $value || $request->server->get('PHP_AUTH_USER') !== $basic) {
                        return null;
                    }
                }
                return $value;
            }
        }
        return null;
    }

    private function mappedBasic(\PDO $database, string $username): string
    {
        $username = explode('@', str_replace('\\', '\\\\', $username), 2)[0];
        $map = $this->setting($database, 'path_basic_mapfile');
        if (is_string($map) && $map !== '' && is_file($map) && is_readable($map)) {
            $records = file($map);
            if ($records === false) {
                throw new \RuntimeException('Basic authentication mapping could not be read.');
            }
            foreach ($records as $record) {
                $columns = str_getcsv($record, ',', '"', '\\');
                if (count($columns) >= 2 && trim($columns[0]) === $username) {
                    return trim($columns[1]);
                }
            }
        }
        return $username;
    }

    private function nativeIp(Request $request, array $config): ?string
    {
        $configured = $config['proxy_headers'] ?? [];
        $headers = $configured === true ? self::PROXY_HEADERS : (is_array($configured) ? array_values(array_intersect($configured, self::PROXY_HEADERS)) : []);
        $headers[] = 'REMOTE_ADDR';
        foreach ($headers as $header) {
            $native = $_SERVER[$header] ?? null;
            if (!is_string($native) || $request->server->get($header) !== $native) {
                continue;
            }
            foreach (explode(',', $native) as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP)) {
                    return $address;
                }
            }
        }
        return null;
    }

    private function record(?Actor $actor, string $reason, string $outcome): void
    {
        $this->audit->record(new AuditEvent(bin2hex(random_bytes(16)), $actor?->id, 'authentication.' . $reason, 'account', $actor === null ? 'unknown' : (string) $actor->id, AuditEvent::ALLOWED, $outcome));
    }
}
