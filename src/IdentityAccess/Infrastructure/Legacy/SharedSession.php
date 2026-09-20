<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Contract\DatabaseConnection;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class SharedSession
{
    public function __construct(
        private RequestStack $requests,
        private LegacyConfiguration $configuration,
        private ReadOnlyDatabaseSessionHandler $databaseHandler,
        private DatabaseConnection $database
    ) {}

    public function read(): array
    {
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
        $forceHttps = $this->database->get()->query("SELECT value FROM settings WHERE name = 'force_https'")->fetchColumn();
        if ($forceHttps === 'on' && !$request->isSecure()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('HTTPS is required.');
        }
        if (session_status() !== PHP_SESSION_NONE) {
            throw new \RuntimeException('Unexpected active session outside the Symfony adapter.');
        }
        if ($config['database_sessions']) {
            session_set_save_handler($this->databaseHandler, true);
        }
        session_name($config['session_name']);
        session_id($id);
        if (!session_start(['use_strict_mode' => true, 'use_only_cookies' => true,
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
            $_SESSION = [];
            session_destroy();
        }
    }
}
