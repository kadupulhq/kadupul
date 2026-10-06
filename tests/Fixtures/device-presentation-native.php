<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Included only by the existing route producer after its locked application/test
// loaders and strict coverage snapshot have been established.
if (PHP_SAPI !== 'cli' || !isset($scenario, $root, $directory)) throw new RuntimeException('Owned route context required');
require_once $root . '/tests/Helpers/NativeDevicePresentation.php';
$pdo = NativeDevicePresentation::database($root, $directory);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$additional = ['settings', 'user_auth', 'user_auth_perms', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_perms'];
$canonical = file_get_contents($root . '/cacti.sql');
if ($canonical === false) throw new RuntimeException('Canonical policy schema unavailable');
foreach ($additional as $table) {
    if (preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \((.*?)\) ENGINE=[^;]+;/s', $canonical, $match) !== 1) throw new RuntimeException('Canonical policy table missing');
    $sql = $match[0];
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $columns = [];
        foreach (explode("\n", $match[1]) as $line) {
            $line = rtrim(trim($line), ',');
            if ($line === '' || preg_match('/^(?:KEY|UNIQUE KEY|CONSTRAINT) /i', $line)) continue;
            $line = preg_replace('/\b(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?/i', 'INTEGER', $line);
            $line = preg_replace('/\b(?:varchar|char)\(\d+\)/i', 'TEXT', $line);
            $line = preg_replace('/\bAUTO_INCREMENT\b|\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $line);
            $line = preg_replace("/\\s+COMMENT\\s+'(?:[^']|'')*'/i", '', $line);
            $columns[] = $line;
        }
        $sql = 'CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')';
    }
    if ($pdo->exec($sql) === false) throw new RuntimeException('Owned policy schema creation failed');
}
foreach (['settings' => [['name' => 'graph_auth_method', 'value' => '3']], 'user_auth' => [['id' => 42, 'username' => 'operator', 'policy_hosts' => 1, 'policy_graphs' => 2, 'policy_graph_templates' => 2]]] + ($scenario['rows'] ?? []) as $table => $rows) {
    if (!in_array($table, array_merge(NativeDevicePresentation::tables(), $additional), true)) throw new RuntimeException('Unregistered fixture table');
    foreach ($rows as $row) {
        $query = $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
        if (!$query instanceof PDOStatement || !$query->execute(array_values($row))) throw new RuntimeException('Owned row seed failed');
    }
}
$snapshot = static function () use ($pdo, $additional): array {
    $data = NativeDevicePresentation::snapshot($pdo);
    foreach ($additional as $table) {
        $rows = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        usort($rows, static fn(array $a, array $b): int => json_encode($a) <=> json_encode($b));
        $data[$table] = $rows;
    }
    return $data;
};
$before = $snapshot();
$prepared = [];
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [NativeDeviceRouteObservedStatement::class, [static function (string $sql) use (&$prepared): void {
    $prepared[] = $sql;
}]]);
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $pdo->exec('PRAGMA query_only=ON');
else $pdo->exec('SET TRANSACTION READ ONLY');
if (!$pdo->beginTransaction()) throw new RuntimeException('Read-only presentation transaction unavailable');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$_ENV['APP_SECRET'] = $_SERVER['APP_SECRET'] = 'owned-device-route-fixture';
$kernel = new Kadupul\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$database = new class ($pdo) implements Kadupul\Platform\Contract\DatabaseConnection {
    public function __construct(private PDO $pdo) {}
    public function get(): PDO
    {
        return $this->pdo;
    }
};
$access = new class implements Kadupul\IdentityAccess\Contract\ConsoleAccess {
    public function consoleActor(): ?Kadupul\IdentityAccess\Contract\Actor
    {
        return new Kadupul\IdentityAccess\Contract\Actor(42, 'operator');
    }
    public function canManageDevices(Kadupul\IdentityAccess\Contract\Actor $actor): bool
    {
        return true;
    }
};
$configuration = new class implements Kadupul\Platform\Contract\LegacyConfiguration {
    public function values(): array
    {
        return ['forced_locale' => 'en-US'];
    }
};
// The installed DBAL middleware wraps the same PDO used for policy, domain
// reads and snapshots. It changes neither SQL nor returned database results.
$dbalConfiguration = new Doctrine\DBAL\Configuration();
$dbalConfiguration->setMiddlewares([new class ($pdo) implements Doctrine\DBAL\Driver\Middleware {
    public function __construct(private PDO $pdo) {}
    public function wrap(Doctrine\DBAL\Driver $driver): Doctrine\DBAL\Driver
    {
        return new class ($driver, $this->pdo) extends Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware {
            public function __construct(Doctrine\DBAL\Driver $driver, private PDO $pdo)
            {
                parent::__construct($driver);
            }
            public function connect(array $params): Doctrine\DBAL\Driver\Connection
            {
                return new Doctrine\DBAL\Driver\PDO\Connection($this->pdo);
            }
        };
    }
}]);
$dbal = Doctrine\DBAL\DriverManager::getConnection(['driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql'], $dbalConfiguration);
$visibility = new Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility($database);
$container->set(Kadupul\Platform\Contract\DatabaseConnection::class, $database);
$container->set(Kadupul\Platform\Contract\LegacyConfiguration::class, $configuration);
$container->set(Kadupul\IdentityAccess\Contract\ConsoleAccess::class, $access);
$container->set(Kadupul\Inventory\Application\Port\DeviceCatalog::class, new Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceCatalog($database, $visibility));
$container->set(Kadupul\Inventory\Application\Port\DeviceLocations::class, new Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceLocations($database, $visibility));
$container->set(Kadupul\Inventory\Application\Port\DeviceEditor::class, new Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceEditor($database, $visibility, $root));
$container->set(Kadupul\Inventory\Application\Port\DeviceAssociationStore::class, new Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceAssociations($database, $visibility, new Kadupul\Inventory\Infrastructure\Legacy\DeviceAssociationRecords(), $root));
$container->set(Kadupul\Inventory\Application\Port\DeviceSites::class, new Kadupul\Inventory\Infrastructure\Persistence\DoctrineDeviceSites($dbal, new Kadupul\Inventory\Infrastructure\Persistence\DoctrineDeviceVisibility($dbal)));
$container->set(Kadupul\Inventory\Application\Port\SiteAssignmentCatalog::class, new Kadupul\Inventory\Infrastructure\Persistence\DoctrineSiteAssignmentCatalog($dbal));
$container->set(Kadupul\Inventory\Application\Port\DeviceCreationCatalog::class, new Kadupul\Inventory\Infrastructure\Persistence\DoctrineDeviceCreationCatalog($dbal));
$response = null;
$container->get('event_dispatcher')->addListener('kernel.response', static function ($event) use (&$response): void {
    $response = $event->getResponse();
});
$GLOBALS['deviceRouteKernel'] = $kernel;
$_GET = ($scenario['method'] ?? 'GET') === 'GET' ? $scenario['fields'] : [];
$_POST = ($scenario['method'] ?? 'GET') === 'POST' ? $scenario['fields'] : [];
$_SERVER['REQUEST_METHOD'] = $scenario['method'] ?? 'GET';
$_SERVER['SCRIPT_FILENAME'] = $directory . '/host.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/host.php';
$_SERVER['REQUEST_URI'] = '/host.php' . ($_GET === [] ? '' : '?' . http_build_query($_GET));
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
ob_start();
require $directory . '/host.php';
$legacyHtml = ob_get_clean();
if (!$response instanceof Symfony\Component\HttpFoundation\Response) throw new RuntimeException('Actual wrapper response missing');
$legacy = ['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'html' => $legacyHtml];
$requests = $scenario['paths'] ?? ($legacy['location'] === null ? [] : [$legacy['location']]);
$responses = [];
$renderedLists = [];
foreach ($requests as $path) {
    // host.php redirects through app.php; Request::create receives the route
    // path after that already-tested compatibility prefix.
    $path = preg_replace('~^/app\.php~', '', $path);
    if (!empty($scenario['json'])) {
        $htmlReply = $kernel->handle(Symfony\Component\HttpFoundation\Request::create($path, 'GET', [], ['Cacti' => 'owned-route-fixture']));
        $renderedLists[] = ['status' => $htmlReply->getStatusCode(), 'html' => $htmlReply->getContent()];
    }

    if (!empty($scenario['json'])) $path = preg_replace('~^/inventory/devices(?=\?|$)~', '/inventory/devices.json', $path);
    $request = Symfony\Component\HttpFoundation\Request::create($path, 'GET', [], ['Cacti' => 'owned-route-fixture']);
    $reply = $kernel->handle($request);
    $responses[] = ['status' => $reply->getStatusCode(), 'html' => $reply->getContent(), 'type' => $reply->headers->get('Content-Type')];
}
$observedStatements = $prepared;
$after = $snapshot();
if (!$pdo->rollBack()) throw new RuntimeException('Cannot close owned read-only transaction');
if ($after !== $before) throw new RuntimeException('Migrated presentation changed owned records');
$kernel->shutdown();
$nativeChildCoverageMarkers = DeviceRouteCoverageRegistration::MARKERS;
$protocol = "\nDEVICE_ROUTE_RESULT=" . json_encode(['legacy' => $legacy, 'responses' => $responses, 'before' => $before, 'after' => $after, 'statements' => $observedStatements, 'rendered_lists' => $renderedLists], JSON_THROW_ON_ERROR) . "\n";
for ($offset = 0; $offset < strlen($protocol); $offset += $written) {
    $written = fwrite(STDOUT, substr($protocol, $offset));
    if ($written === false || $written === 0) throw new RuntimeException('Incomplete owned CLI receipt');
}

/** Counts native PDO statement preparations without replacing SQL or results. */
final class NativeDeviceRouteObservedStatement extends PDOStatement
{
    private function __construct(Closure $observe)
    {
        $observe($this->queryString);
    }
}
