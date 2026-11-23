<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\IdentityAccess\Infrastructure\Legacy\NativeAuthenticationSession;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 2) . '/include/vendor/autoload.php';
$input = json_decode((string) stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
if (!is_array($input) || !is_string($input['root'] ?? null) || !is_int($input['actor'] ?? null) || !is_string($input['credential'] ?? null) || !is_bool($input['database'] ?? null)) {
    throw new RuntimeException('Invalid native utility session fixture.');
}
session_save_path($input['root'] . '/sessions');
$configuration = new class ($input) implements LegacyConfiguration {
    public function __construct(private array $input) {}
    public function values(): array
    {
        return ['root' => $this->input['root'], 'session_name' => 'BrowserRealmDenialFixture', 'database_sessions' => $this->input['database'], 'url_path' => '/fixture/', 'cookie_domain' => ''];
    }
};
$database = new class implements DatabaseConnection {
    public function get(): PDO
    {
        return new PDO(getenv('KADUPUL_BROWSER_DENIAL_WORKER_DSN'), getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
};
$session = (new NativeAuthenticationSession($configuration, $database))->establish($input['actor'], Request::create('http://localhost/fixture/user_group_admin.php'), '127.0.0.1', $input['credential']);
print json_encode(['session_id' => $session], JSON_THROW_ON_ERROR);
