<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Kernel;
use Kadupul\Platform\Application\Port\RrdCheckAccess;
use Kadupul\Platform\Application\Port\SystemDiagnostics;
use Kadupul\Platform\Application\Query\RrdCheckAccessDenied;
use Kadupul\Platform\Application\ReadModel\DiagnosticPage;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Domain\Diagnostics\DiagnosticFilters;
use Kadupul\Platform\Domain\Diagnostics\DiagnosticScreen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SystemDiagnosticsRoutesTest extends TestCase
{
    private Kernel $kernel;
    private ?Actor $actor;
    private bool $denied = false;
    private bool $failed = false;
    private bool $empty = false;
    private array $reads = [];

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->actor = new Actor(42, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(fn(): ?Actor => $this->actor);
        $container->set(ConsoleAccess::class, $console);
        $access = $this->createMock(RrdCheckAccess::class);
        $access->method('authorize')->willReturnCallback(fn(): Actor => $this->denied ? throw new RrdCheckAccessDenied(false) : $this->actor);
        $container->set(RrdCheckAccess::class, $access);
        $reader = $this->createMock(SystemDiagnostics::class);
        $reader->method('read')->willReturnCallback(function (DiagnosticScreen $screen, DiagnosticFilters $filters, int $actorId): DiagnosticPage {
            $this->reads[] = [$screen, $filters, $actorId];
            if ($this->failed) {
                throw new \RuntimeException('Private database credentials');
            }
            return new DiagnosticPage(['Name', 'Value'], $this->empty ? [] : [['<script>alert(1)</script>', 'Router <b>'], ['zero', 0]], !$this->empty);
        });
        $container->set(SystemDiagnostics::class, $reader);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturn(['collector_id' => 1, 'url_path' => '/monitoring/']);
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public static function screens(): iterable
    {
        foreach (DiagnosticScreen::cases() as $screen) {
            yield $screen->value => [$screen, '/utilities/system' . ($screen === DiagnosticScreen::Menu ? '' : '/' . $screen->value)];
        }
    }

    #[DataProvider('screens')]
    public function testEveryScreenIsReachableEscapedAndReadOnly(DiagnosticScreen $screen, string $path): void
    {
        $response = $this->request($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $html = (string) $response->getContent();
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//main[@id="system-diagnostics"]/h1')->length);
        self::assertSame(10, $xpath->query('//main/nav[1]//a')->length);
        self::assertSame(1, $xpath->query('//main/nav[1]//a[@aria-current="page"]')->length);
        self::assertStringContainsString('/monitoring/utilities.php', $html);
        self::assertSame(0, $xpath->query('//main//script | //main//*[@onclick] | //main//form[@method="post"]')->length);
        if ($screen !== DiagnosticScreen::Menu) {
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
            self::assertStringNotContainsString('<script>alert', $html);
            self::assertSame(2, $xpath->query('//main//thead//th[@scope="col"]')->length);
            self::assertSame(1, $xpath->query('//main//a[@rel="next"]')->length);
        }
        self::assertSame($screen, $this->reads[0][0]);
        self::assertSame(42, $this->reads[0][2]);
        self::assertSame(200, $this->request($path, 'HEAD')->getStatusCode());
        self::assertSame('', $this->request($path, 'HEAD')->getContent());
        $before = count($this->reads);
        self::assertSame(405, $this->request($path, 'POST')->getStatusCode());
        self::assertCount($before, $this->reads);
    }

    #[DataProvider('screens')]
    public function testAuthorizationPrecedesMalformedInputAndDataReads(DiagnosticScreen $screen, string $path): void
    {
        $this->actor = null;
        self::assertSame(401, $this->request($path . '?filter[]=bad')->getStatusCode());
        $this->actor = new Actor(42, 'operator');
        $this->denied = true;
        self::assertSame(403, $this->request($path . '?filter[]=bad')->getStatusCode());
        self::assertSame([], $this->reads);
    }

    public function testFiltersCarryThroughPagingAndKeepAnExplicitZeroDevice(): void
    {
        $response = $this->request('/utilities/system/poller-cache?filter=router&host_id=0&template_id=0&poller_action=0&status=0&rows=10&page=2');
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        foreach (['host_id=0', 'template_id=0', 'poller_action=0', 'status=0', 'rows=10', 'filter=router', 'page=3'] as $part) {
            self::assertStringContainsString($part, $html);
        }
        self::assertSame(10, $this->reads[0][1]->offset());
        self::assertStringContainsString('name="host_id" value="0"', $html);
        self::assertStringNotContainsString('name="page"', $html);
    }

    public function testMalformedShapesBoundsAndUnexpectedFieldsNeverReachTheReader(): void
    {
        foreach (['filter[]=x', 'rows=0', 'rows=1000000', 'rows=-1', 'page=0', 'page=100000', 'page=1e3', 'page=1.2', 'host_id=-2', 'host_id=16777216', 'host_id[]=1', 'poller_action=3', 'status=2', 'filter=%00', 'filter=' . str_repeat('x', 513), 'purge=1', 'screen=php'] as $query) {
            self::assertSame(400, $this->request('/utilities/system/poller-cache?' . $query)->getStatusCode(), $query);
        }
        self::assertSame(400, $this->request('/utilities/system/data-query-cache?with_index=2')->getStatusCode());
        self::assertSame([], $this->reads);
    }

    public function testUnicodeSearchUsesTheSameCharacterLimitAsTheBrowser(): void
    {
        self::assertSame(200, $this->request('/utilities/system/php?filter=' . rawurlencode(str_repeat('é', 512)))->getStatusCode());
        self::assertSame(400, $this->request('/utilities/system/php?filter=' . rawurlencode(str_repeat('é', 513)))->getStatusCode());
        self::assertCount(1, $this->reads);
    }

    public function testEmptyAndFailedReadsHaveDistinctOutcomesWithoutPrivateErrors(): void
    {
        $this->empty = true;
        $empty = $this->request('/utilities/system/php');
        self::assertSame(200, $empty->getStatusCode());
        self::assertStringContainsString('No diagnostic records found.', (string) $empty->getContent());
        self::assertStringNotContainsString('rel="next"', (string) $empty->getContent());
        $this->failed = true;
        $error = $this->request('/utilities/system/php');
        self::assertSame(502, $error->getStatusCode());
        self::assertStringNotContainsString('Private database', (string) $error->getContent());
    }

    public function testLinksRetainTheInstallationAndFrontControllerBasePath(): void
    {
        $request = Request::create('https://localhost/monitoring/app.php/utilities/system/php?rows=10&page=2', server: [
            'SCRIPT_NAME' => '/monitoring/app.php', 'SCRIPT_FILENAME' => '/srv/monitoring/app.php',
            'PHP_SELF' => '/monitoring/app.php/utilities/system/php',
        ]);
        $response = $this->kernel->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('href="/monitoring/app.php/utilities/system"', (string) $response->getContent());
        self::assertStringContainsString('href="/monitoring/utilities.php?action=view_tech&amp;tab=phpinfo"', (string) $response->getContent());
        self::assertStringContainsString('/monitoring/app.php/utilities/system/php?filter=', (string) $response->getContent());
    }

    public function testLocaleIsSelectedThroughTheRealPreferenceSubscriber(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
        $pdo->exec("INSERT INTO settings VALUES ('i18n_language_support','1'), ('i18n_auto_detection','0'), ('i18n_default_language','en')");
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
        $preference = $this->createMock(LocalePreference::class);
        $preference->method('preferredLocale')->willReturn('fr-CA');
        $container->set(LocalePreference::class, $preference);
        foreach (self::screens() as [$screen, $path]) {
            $response = $this->kernel->handle(Request::create($path, cookies: ['Cacti' => 'test']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('<html lang="fr">', (string) $response->getContent());
            self::assertStringContainsString('Utilitaires système', (string) $response->getContent());
        }
    }

    public function testCollectorRoutesRefuseOfflineFallback(): void
    {
        $directory = sys_get_temp_dir() . '/diagnostic-configuration-' . bin2hex(random_bytes(8));
        mkdir($directory . '/include', 0700, true);
        $source = "<?php\n\$poller_id = 2;\n\$database_hostname = 'collector-db';\n\$database_username = 'local';\n\$database_default = 'local';\n\$rdatabase_hostname = 'primary-db';\n\$rdatabase_username = 'primary';\n\$rdatabase_default = 'primary';\n\$rdatabase_password = '';\n";
        try {
            foreach (DiagnosticScreen::cases() as $screen) {
                $request = Request::create('/utilities/system');
                $request->attributes->set('_route', 'platform_diagnostics_' . str_replace('-', '_', $screen->value));
                $requests = new \Symfony\Component\HttpFoundation\RequestStack();
                $requests->push($request);
                file_put_contents($directory . '/include/config.php', $source . "\$conn_mode = 'offline';\n");
                try {
                    (new \Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration($directory, $requests))->values();
                    self::fail('Offline collector must not fall back to its local database.');
                } catch (\RuntimeException $error) {
                    self::assertSame('Online primary configuration is required for collector administration.', $error->getMessage());
                }
            }
        } finally {
            unlink($directory . '/include/config.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }

    private function request(string $path, string $method = 'GET'): Response
    {
        return $this->kernel->handle(Request::create($path, $method));
    }
}
