<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$root = dirname(__DIR__, 3);
require $root . '/include/vendor/autoload.php';
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__health') {
    print 'ready';
    return;
}
$pdo = new class ('sqlite:' . getenv('ABOUT_REPRO_DATABASE'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
    private bool $unconfirmed = false;
    private ?string $lateState = null;

    public function errorCode(): ?string
    {
        return $this->unconfirmed ? $this->lateState : parent::errorCode();
    }

    public function errorInfo(): array
    {
        return $this->unconfirmed ? [$this->lateState, 7, 'Fixture transaction state'] : parent::errorInfo();
    }

    public function commit(): bool
    {
        $mode = $this->query("SELECT value FROM settings WHERE name = 'fixture_commit_failure'")->fetchColumn();
        if ($mode === 'before') {
            return false;
        }
        $confirmed = parent::commit();
        if (in_array($mode, ['late', 'unknown'], true)) {
            $this->unconfirmed = true;
            $this->lateState = $mode === 'late' ? '08006' : null;
        }
        return $mode === 'after' ? false : $confirmed;
    }

    public function rollBack(): bool
    {
        $mode = $this->query("SELECT value FROM settings WHERE name = 'fixture_rollback_failure'")->fetchColumn();
        if ($mode === 'on') {
            return false;
        }
        $confirmed = parent::rollBack();
        if (in_array($mode, ['late', 'unknown'], true)) {
            $this->unconfirmed = true;
            $this->lateState = $mode === 'late' ? '08006' : null;
        }
        return $confirmed;
    }
};
// This fixture's HTTP server verifies Basic credentials before exposing its
// native identity. Application restoration still uses the real Symfony binding.
$unprotected = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__unprotected/about';
if ($unprotected) {
    $_SERVER['REQUEST_URI'] = '/about';
}
if (!$unprotected && isset($_SERVER['PHP_AUTH_USER'])) {
    $statement = $pdo->prepare('SELECT password FROM user_auth WHERE username = ? AND realm = 2');
    $statement->execute([$_SERVER['PHP_AUTH_USER']]);
    $hash = $statement->fetchColumn();
    if (!is_string($hash) || !password_verify($_SERVER['PHP_AUTH_PW'] ?? '', $hash)) {
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    } else {
        $_SERVER['REMOTE_USER'] = $_SERVER['PHP_AUTH_USER'];
    }
}
$database = new class ($pdo) implements \Kadupul\Platform\Contract\DatabaseConnection {
    public function __construct(private PDO $pdo) {}
    public function get(): PDO
    {
        return $this->pdo;
    }
};
$configuration = new class implements \Kadupul\Platform\Contract\LegacyConfiguration {
    public function values(): array
    {
        return ['collector_id' => 1, 'root' => dirname(__DIR__, 3), 'forced_locale' => null,
            'session_name' => 'AboutFixture', 'database_sessions' => getenv('ABOUT_REPRO_STORAGE') === 'database', 'cookie_domain' => '', 'url_path' => '/'];
    }
};
$kernel = new \Kadupul\Kernel('test', true);
try {
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $container->set(\Kadupul\Platform\Contract\DatabaseConnection::class, $database);
    $container->set(\Kadupul\Platform\Contract\LegacyConfiguration::class, $configuration);
    $container->set('kadupul.session_database', $database);
    if ($pdo->query("SELECT value FROM settings WHERE name = 'fixture_audit_failure'")->fetchColumn() === 'on' || $pdo->query("SELECT value FROM settings WHERE name = 'fixture_failure_audit_failure'")->fetchColumn() === 'on') {
        $container->set(\Kadupul\IdentityAccess\Contract\AuditTrail::class, new class ($pdo) implements \Kadupul\IdentityAccess\Contract\AuditTrail {
            public function __construct(private PDO $database) {}
            public function record(\Kadupul\IdentityAccess\Contract\AuditEvent $event): void
            {
                if ($event->outcome === \Kadupul\IdentityAccess\Contract\AuditEvent::SUCCEEDED || $this->database->query("SELECT value FROM settings WHERE name = 'fixture_failure_audit_failure'")->fetchColumn() === 'on') {
                    throw new RuntimeException('Fixture audit persistence failure.');
                }
            }
        });
    }

    $response = $kernel->handle(\Symfony\Component\HttpFoundation\Request::createFromGlobals());
    $response->send();
} finally {
    $kernel->shutdown();
}
