<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\HttpFoundation\Request;

final readonly class NativeAuthenticationSession
{
    public function __construct(private LegacyConfiguration $configuration, private DatabaseConnection $database) {}

    public function establish(int $user, Request $request, string $ip): string
    {
        if ($user <= 0 || session_status() !== PHP_SESSION_NONE || headers_sent()) {
            throw new \RuntimeException('Authentication session cannot be established.');
        }
        $config = $this->configuration->values();
        $length = (int) ini_get('session.sid_length');
        if ($length < 22 || $length > 256 || ($config['database_sessions'] && $length > 32)) {
            throw new \RuntimeException('Configured authentication session ID exceeds storage capacity.');
        }
        $handler = $config['database_sessions']
            ? new AuthenticationDatabaseSessionHandler($this->database, $ip, (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'))
            : new AuthenticationFileSessionHandler();
        if (!session_set_save_handler($handler, true)) {
            throw new \RuntimeException('Authentication session storage is unavailable.');
        }
        session_name($config['session_name']);
        $created = null;
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException('Authentication session operation failed.', 0, $severity, $file, $line);
        }, E_WARNING);
        try {
            if (!session_start(['use_strict_mode' => true, 'use_cookies' => false,
                'use_only_cookies' => true, 'use_trans_sid' => false,
                'cookie_httponly' => true, 'cookie_samesite' => 'Strict',
                'cookie_path' => $config['url_path'], 'cookie_domain' => $config['cookie_domain'],
                'cookie_secure' => $request->isSecure()])) {
                throw new \RuntimeException('Authentication session could not be opened.');
            }
            $previous = session_id();
            // Drop anonymous credential/permission/CSRF caches at the boundary.
            $_SESSION = [];
            if (!session_regenerate_id(true) || session_id() === $previous) {
                throw new \RuntimeException('Authentication session could not be rotated.');
            }
            $created = session_id();
            $_SESSION = ['sess_user_id' => $user, 'cacti_cwd' => $config['root']];
            session_write_close();
            if (session_status() !== PHP_SESSION_NONE || !$handler->written) {
                throw new \RuntimeException('Authentication session was not persisted.');
            }
            return $created;
        } catch (\Throwable $failure) {
            try {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION = [];
                    if (!session_destroy()) {
                        throw new \RuntimeException('Failed authentication session cleanup.', 0, $failure);
                    }
                } elseif ($created !== null) {
                    $this->revoke($created, $request);
                }
            } finally {
                $this->expireCookie($request);
            }
            throw $failure;
        } finally {
            restore_error_handler();
        }
    }

    /** Expose the credential only after the transaction and audit are confirmed. */
    public function publish(string $id, Request $request): void
    {
        if (session_status() !== PHP_SESSION_NONE || session_id() !== $id || !preg_match('/\A[a-zA-Z0-9,-]{22,256}\z/D', $id) || headers_sent()) {
            throw new \RuntimeException('Authentication cookie cannot be published.');
        }
        $config = $this->configuration->values();
        if (!setcookie($config['session_name'], $id, ['path' => $config['url_path'], 'domain' => $config['cookie_domain'], 'secure' => $request->isSecure(), 'httponly' => true, 'samesite' => 'Strict'])) {
            throw new \RuntimeException('Authentication cookie publication was not confirmed.');
        }
    }

    public function revoke(string $id, Request $request): void
    {
        $config = $this->configuration->values();
        try {
            if ($config['database_sessions']) {
                BrowserAuthenticationSql::execute($this->database->get(), 'DELETE FROM sessions WHERE id = ?', [$id]);
            } else {
                if (session_status() !== PHP_SESSION_NONE || session_id() !== $id) {
                    throw new \RuntimeException('Unexpected authentication session during cleanup.');
                }
                if (!session_start(['use_cookies' => false]) || !session_destroy()) {
                    throw new \RuntimeException('Failed authentication session cleanup.');
                }
            }
        } finally {
            $this->expireCookie($request);
        }
    }

    private function expireCookie(Request $request): void
    {
        $config = $this->configuration->values();
        if (!setcookie($config['session_name'], '', ['expires' => time() - 3600, 'path' => $config['url_path'], 'domain' => $config['cookie_domain'], 'secure' => $request->isSecure(), 'httponly' => true, 'samesite' => 'Strict'])) {
            throw new \RuntimeException('Failed authentication cookie cleanup.');
        }
    }
}
