<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = getenv('DEVICE_ROUTE_ROOT');
$directory = getenv('DEVICE_ROUTE_DIRECTORY');
$encoded = getenv('DEVICE_ROUTE_SCENARIO');
$scenario = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
// Cached Symfony containers include the application's contract files directly.
// Load those contracts from the application stack; keep PHPUnit/coverage on the
// parent's locked test stack without redirecting framework namespaces there.
require $root . '/include/vendor/autoload.php';
if (!interface_exists(Psr\Container\ContainerInterface::class)) throw new RuntimeException('Application container contract missing');
require $root . '/tests/vendor/autoload.php';
$loader = Composer\Autoload\ClassLoader::getRegisteredLoaders()[$root . '/tests/vendor'];
$loader->unregister();
$loader->register(false);
spl_autoload_register(static function (string $class) use ($loader): void {
    foreach (['PHPUnit\\', 'SebastianBergmann\\', 'Pest\\', 'NunoMaduro\\', 'TheSeer\\', 'PhpParser\\', 'DeepCopy\\'] as $prefix) {
        if (str_starts_with($class, $prefix)) {
            $loader->loadClass($class);
            return;
        }
    }
}, true, true);
if ((new ReflectionClass(Psr\Container\ContainerInterface::class))->getFileName() !== realpath($root . '/include/vendor/psr/container/src/ContainerInterface.php')
    || (new ReflectionClass(PHPUnit\Framework\TestCase::class))->getFileName() !== realpath($root . '/tests/vendor/phpunit/phpunit/src/Framework/TestCase.php')
    || (new ReflectionClass(SebastianBergmann\CodeCoverage\CodeCoverage::class))->getFileName() !== realpath($root . '/tests/vendor/phpunit/php-code-coverage/src/CodeCoverage.php')) {
    throw new RuntimeException('Native application/test dependency boundary changed');
}
require $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
require $root . '/tests/Helpers/DeviceRouteCoverageRegistration.php';
if (getenv('DEVICE_ROUTE_COVERAGE') === '1') {
    $nativeChildCoverageSnapshot = NativeChildCoverageEvidence::snapshot($root, 'tests/Fixtures/device-route-native.php', $encoded, DeviceRouteCoverageRegistration::SOURCES);
    define('DEVICE_ROUTE_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    if (in_array($scenario['kind'], ['wrapper', 'presentation'], true)) {
        define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/host.php');
        define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/host.php');
    }
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}

if ($scenario['kind'] === 'presentation') {
    require $root . '/tests/Fixtures/device-presentation-native.php';
    exit;
}

if ($scenario['kind'] === 'destinations') {
    $config = '<?xml version="1.0"?><phpunit cacheResult="false" failOnWarning="true" failOnRisky="true"><php><env name="APP_ENV" value="test"/><env name="APP_SECRET" value="owned-device-route-fixture"/></php><testsuites><testsuite name="active device destinations">';
    foreach (DeviceRouteCoverageRegistration::SUITES as $suite) {
        $config .= '<file>' . $root . '/tests/Symfony/' . $suite . '.php</file>';
    }
    $config .= '</testsuite></testsuites></phpunit>';
    file_put_contents($directory . '/phpunit.xml', $config);
    $exit = (new PHPUnit\TextUI\Application())->run(['phpunit', '--configuration', $directory . '/phpunit.xml', '--no-coverage', '--colors=never', '--log-junit', $directory . '/junit.xml']);
    if ($exit !== 0) {
        throw new RuntimeException('Active destination regressions failed with status ' . $exit);
    }
    $junit = simplexml_load_file($directory . '/junit.xml');
    if ($junit === false || (int) $junit->testsuite['tests'] < 100 || (int) $junit->testsuite['failures'] !== 0 || (int) $junit->testsuite['errors'] !== 0 || (int) $junit->testsuite['skipped'] !== 0) throw new RuntimeException('Incomplete destination suite');
    $nativeChildCoverageMarkers = DeviceRouteCoverageRegistration::MARKERS;
    echo "\nDEVICE_ROUTE_RESULT=" . json_encode(['exit' => $exit, 'tests' => (int) $junit->testsuite['tests'], 'assertions' => (int) $junit->testsuite['assertions']], JSON_THROW_ON_ERROR) . "\n";
    exit;
}

// Bootstrap, account/session and remote worker capabilities are explicit ports.
// This path executes the byte-identical wrapper and installed Symfony routing;
// the destination suite separately exercises real application/form contracts.
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$_ENV['APP_SECRET'] = $_SERVER['APP_SECRET'] = 'owned-device-route-fixture';
$kernel = new Kadupul\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$pdo = new PDO('sqlite:' . $directory . '/state.sqlite');
$pdo->exec('CREATE TABLE settings (name TEXT, value TEXT); CREATE TABLE fixture_device (id INTEGER PRIMARY KEY, description TEXT); INSERT INTO fixture_device VALUES (12, "Allowed"), (13, "Denied")');
$before = $pdo->query('SELECT * FROM fixture_device ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$database = new class ($pdo) implements Kadupul\Platform\Contract\DatabaseConnection {
    public function __construct(private PDO $pdo) {}
    public function get(): PDO
    {
        return $this->pdo;
    }
};
$configuration = new class implements Kadupul\Platform\Contract\LegacyConfiguration {
    public function values(): array
    {
        return ['forced_locale' => 'en-US'];
    }
};
$access = new class ($scenario['access']) implements Kadupul\IdentityAccess\Contract\ConsoleAccess {
    public function __construct(private string $mode) {}
    public function consoleActor(): ?Kadupul\IdentityAccess\Contract\Actor
    {
        return $this->mode === 'anonymous' ? null : new Kadupul\IdentityAccess\Contract\Actor(42, 'operator');
    }
    public function canManageDevices(Kadupul\IdentityAccess\Contract\Actor $actor): bool
    {
        return $this->mode === 'manager';
    }
};
$locations = new class implements Kadupul\Inventory\Application\Port\DeviceLocations {
    public function matching(int $actorId, string $term): array
    {
        throw new RuntimeException('Unexpected protected location lookup');
    }
};
$container->set(Kadupul\Platform\Contract\DatabaseConnection::class, $database);
$container->set(Kadupul\Platform\Contract\LegacyConfiguration::class, $configuration);
$container->set(Kadupul\IdentityAccess\Contract\ConsoleAccess::class, $access);
$container->set(Kadupul\Inventory\Application\Port\DeviceLocations::class, $locations);
$response = null;
$container->get('event_dispatcher')->addListener('kernel.response', static function ($event) use (&$response): void {
    $response = $event->getResponse();
});
$GLOBALS['deviceRouteKernel'] = $kernel;
$_GET = $scenario['method'] === 'GET' ? $scenario['fields'] : [];
$_POST = $scenario['method'] === 'POST' ? $scenario['fields'] : [];
$base = $scenario['base'];
$_SERVER['REQUEST_METHOD'] = $scenario['method'];
$_SERVER['SCRIPT_FILENAME'] = $directory . '/host.php';
$_SERVER['SCRIPT_NAME'] = $base . '/host.php';
$_SERVER['PHP_SELF'] = $base . '/host.php';
$_SERVER['REQUEST_URI'] = $base . '/host.php' . ($_GET === [] ? '' : '?' . http_build_query($_GET));
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
ob_start();
require $directory . '/host.php';
$html = ob_get_clean();
if (!$response instanceof Symfony\Component\HttpFoundation\Response) {
    throw new RuntimeException('Wrapper did not reach the real Symfony response');
}
$after = $pdo->query('SELECT * FROM fixture_device ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if ($after !== $before) {
    throw new RuntimeException('Legacy compatibility request changed owned device state');
}
$kernel->shutdown();
$nativeChildCoverageMarkers = DeviceRouteCoverageRegistration::MARKERS;
echo "\nDEVICE_ROUTE_RESULT=" . json_encode(['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'html' => $html, 'before' => $before, 'after' => $after], JSON_THROW_ON_ERROR) . "\n";
