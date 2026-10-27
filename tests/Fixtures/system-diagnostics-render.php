<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
require $root . '/include/vendor/autoload.php';
$theme = $argv[1] ?? 'modern';
if (!preg_match('/\A[a-z0-9_-]+\z/D', $theme) || !is_file($root . '/include/themes/' . $theme . '/main.css')) {
    throw new InvalidArgumentException('Unknown installed theme.');
}
$kernel = new Kadupul\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$container->set(Kadupul\IdentityAccess\Contract\ConsoleAccess::class, new class implements Kadupul\IdentityAccess\Contract\ConsoleAccess {
    public function consoleActor(): ?Kadupul\IdentityAccess\Contract\Actor { return new Kadupul\IdentityAccess\Contract\Actor(42, 'fixture'); }
    public function canManageDevices(Kadupul\IdentityAccess\Contract\Actor $actor): bool { return false; }
});
$container->set(Kadupul\Platform\Application\Port\RrdCheckAccess::class, new class implements Kadupul\Platform\Application\Port\RrdCheckAccess {
    public function authorize(): Kadupul\IdentityAccess\Contract\Actor { return new Kadupul\IdentityAccess\Contract\Actor(42, 'fixture'); }
    public function assertCurrent(int $actorId): void {}
});
$container->set(Kadupul\Platform\Application\Port\SystemDiagnostics::class, new class implements Kadupul\Platform\Application\Port\SystemDiagnostics {
    public function read(Kadupul\Platform\Domain\Diagnostics\DiagnosticScreen $screen, Kadupul\Platform\Domain\Diagnostics\DiagnosticFilters $filters, int $actorId): Kadupul\Platform\Application\ReadModel\DiagnosticPage {
        return new Kadupul\Platform\Application\ReadModel\DiagnosticPage(['Name', 'Value'], [['<script>window.bad = 1</script>', 'Escaped <b>'], ['Zero', 0]], true);
    }
});
$container->set(Kadupul\Platform\Contract\LegacyConfiguration::class, new class implements Kadupul\Platform\Contract\LegacyConfiguration {
    public function values(): array { return ['collector_id' => 1, 'url_path' => '/']; }
});

$container->set(Kadupul\IdentityAccess\Contract\AuthenticatedAccess::class, new class implements Kadupul\IdentityAccess\Contract\AuthenticatedAccess {
    public function authenticatedActor(): ?Kadupul\IdentityAccess\Contract\Actor { return new Kadupul\IdentityAccess\Contract\Actor(42, 'fixture'); }
});
$container->set(Kadupul\Platform\Contract\DatabaseConnection::class, new class ($theme) implements Kadupul\Platform\Contract\DatabaseConnection {
    private PDO $database;
    public function __construct(string $theme) {
        $this->database = new PDO('sqlite::memory:');
        $this->database->exec('CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT)');
        $this->database->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $query = $this->database->prepare('INSERT INTO settings_user VALUES (42, ?, ?)');
        $query->execute(['selected_theme', $theme]);
    }
    public function get(): PDO { return $this->database; }
});
$pages = [];
try {
    foreach (Kadupul\Platform\Domain\Diagnostics\DiagnosticScreen::cases() as $screen) {
        $uri = '/utilities/system' . ($screen->value === 'menu' ? '' : '/' . $screen->value);
        $request = Symfony\Component\HttpFoundation\Request::create($uri);
        $response = $kernel->handle($request);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Diagnostic fixture did not render: ' . $screen->value);
        }
        $headers = [];
        foreach ($response->headers->all() as $name => $values) { $headers[$name] = implode(', ', $values); }
        $pages[$uri] = ['body' => $response->getContent(), 'headers' => $headers];
        $kernel->terminate($request, $response);
    }
    echo json_encode($pages, JSON_THROW_ON_ERROR);
} finally {
    $kernel->shutdown();
}
