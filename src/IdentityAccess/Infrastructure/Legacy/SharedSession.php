<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\HttpFoundation\RequestStack;

final class SharedSession
{
    private ?array $pendingRevocation = null;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly LegacyConfiguration $configuration,
        private readonly ReadOnlyDatabaseSessionHandler $databaseHandler,
        private readonly DatabaseConnection $database
    ) {}

    public function read(): array
    {
        if ($this->pendingRevocation !== null) {
            // A rejected identity cannot be resumed while its writer rolls back.
            // Complete durable cleanup once the caller's transaction is finished.
            if (!$this->database->get()->inTransaction()) {
                $this->completeRevocation();
            }
            return [];
        }
        $request = $this->requests->getCurrentRequest();
        if ($request === null || $request->cookies->count() === 0) {
            return [];
        }
        if (!array_filter($request->cookies->all(), static fn($value) => is_string($value) && preg_match('/\A[a-zA-Z0-9,-]{22,256}\z/D', $value))) {
            return [];
        }
        $config = $this->configuration->values();
        $id = $request->cookies->get($config['session_name']);
        if (!is_string($id) || !preg_match('/\A[a-zA-Z0-9,-]{22,256}\z/D', $id)) {
            return [];
        }
        // Only the actual HTTP cookie may select a native session. A synthetic
        // Request or query parameter must not supply a session identifier.
        if (($_COOKIE[$config['session_name']] ?? null) !== $id) {
            return [];
        }
        $forceHttps = $this->database->get()->query("SELECT value FROM settings WHERE name = 'force_https'")->fetchColumn();
        if ($forceHttps === 'on' && !$request->isSecure()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('HTTPS is required.');
        }
        if (session_status() !== PHP_SESSION_NONE) {
            throw new \RuntimeException('Unexpected active session outside the Symfony adapter.');
        }
        if (session_id() !== '' && !hash_equals($id, session_id())) {
            throw new \RuntimeException('Unexpected native session identifier.');
        }
        if ($config['database_sessions']) {
            session_set_save_handler($this->databaseHandler, true);
        }
        session_name($config['session_name']);
        // Let PHP validate and resume its cookie; never assign a supplied ID.
        if (!session_start(['use_strict_mode' => true, 'use_cookies' => true,
            'use_only_cookies' => true, 'use_trans_sid' => false,
            'cookie_httponly' => true, 'cookie_samesite' => 'Strict',
            'cookie_path' => $config['url_path'], 'cookie_domain' => $config['cookie_domain'],
            'cookie_secure' => $request->isSecure()])) {
            throw new \RuntimeException('Shared session could not be opened.');
        }
        $snapshot = $_SESSION;
        if (($snapshot['cacti_cwd'] ?? '') !== $config['root']) {
            session_destroy();
            return [];
        }
        session_write_close();
        return $snapshot;
    }

    public function revoke(): void
    {
        if (session_status() === PHP_SESSION_NONE && session_id() !== '') {
            session_start();
            if ($this->database->get()->inTransaction()) {
                // A denied writer must roll back its own transaction first.
                // Retain token ownership, never the authenticated identity.
                if ($this->pendingRevocation !== null && !hash_equals($this->pendingRevocation['id'], session_id())) {
                    throw new \RuntimeException('Native session ownership changed during revocation.');
                }
                $this->pendingRevocation ??= ['id' => session_id(),
                    'session_token' => $_SESSION['sess_remember_token'] ?? null,
                    'cookie' => $_COOKIE['cacti_remembers'] ?? null];
                session_write_close();
                $_SESSION = [];
                $this->expireRememberedCookie();
                return;
            }
            require_once dirname(__DIR__, 4) . '/lib/auth.php';
            // Reuse the legacy realm-aware token revocation before discarding
            // the session's remembered-token ownership metadata.
            \clear_auth_cookie($this->database->get(), static function (): void {});
            $this->expireRememberedCookie();
            $_SESSION = [];
            session_destroy();
        }
    }

    public function completeRevocation(): void
    {
        if ($this->pendingRevocation === null) {
            return;
        }
        if ($this->database->get()->inTransaction()) {
            throw new \RuntimeException('Session revocation requires the caller transaction to finish.');
        }
        require_once dirname(__DIR__, 4) . '/lib/auth.php';
        $pending = $this->pendingRevocation;
        \clear_auth_cookie($this->database->get(), static function (): void {}, $pending);
        if ($this->configuration->values()['database_sessions']) {
            if (!$this->databaseHandler->destroy($pending['id'])) {
                throw new \RuntimeException('Persisted session could not be revoked.');
            }
        } else {
            if (session_status() !== PHP_SESSION_NONE || !hash_equals($pending['id'], session_id())) {
                throw new \RuntimeException('Native session ownership changed before revocation.');
            }
            if (!session_start()) {
                throw new \RuntimeException('Native session could not be reopened for revocation.');
            }
            $_SESSION = [];
            if (!session_destroy()) {
                throw new \RuntimeException('Native session could not be revoked.');
            }
        }
        $this->pendingRevocation = null;
    }

    private function expireRememberedCookie(): void
    {
        $config = $this->configuration->values();
        $request = $this->requests->getCurrentRequest();
        setcookie('cacti_remembers', '', ['expires' => time() - 3600,
            'path' => $config['url_path'], 'domain' => $config['cookie_domain'],
            'secure' => $request?->isSecure() ?? false, 'httponly' => true, 'samesite' => 'Strict']);
        unset($_COOKIE['cacti_remembers']);
        $request?->cookies->remove('cacti_remembers');
    }
}
