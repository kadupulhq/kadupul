<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\CreateDevice;
use Kadupul\Inventory\Application\Port\DeviceCreator;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\NewDevice;
use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class DeviceCreateTest extends TestCase
{
    public function testCreationNormalizesAndKeepsConfiguredSecretsOutOfPayload(): void
    {
        $device = new NewDevice(['description' => ' 東京 ', 'hostname' => '127.0.0.1', 'site_id' => 3]);
        self::assertSame('東京', $device->fields['description']);
        self::assertSame('3', $device->fields['site_id']);
        self::assertTrue($device->fields['use_default_credentials']);
        self::assertSame('', $device->fields['snmp_community']);
        $secure = new NewDevice(['description' => 'V3', 'hostname' => '::1', 'snmp_version' => '3', 'snmp_username' => 'operator', 'snmp_auth_protocol' => 'SHA256', 'snmp_priv_protocol' => 'AES', 'use_default_credentials' => false, 'snmp_password' => 'fixture-auth', 'snmp_priv_passphrase' => 'fixture-privacy']);
        self::assertSame('SHA256', $secure->fields['snmp_auth_protocol']);
    }

    public static function invalidFields(): iterable
    {
        foreach (['description' => ['', str_repeat('東', 151), "a\0b"], 'hostname' => ['', 'https://example.invalid'], 'enabled' => ['on'], 'poller_id' => ['0', '65536'], 'snmp_port' => ['-1', '65536'], 'device_threads' => ['256'], 'snmp_version' => ['4'], 'bulk_walk_size' => ['-2','61'], 'snmp_password' => ['must-not-be-ignored'], 'site_id' => ['1 OR 1=1'], 'snmp_auth_protocol' => ['invalid'], 'notes' => [[1], "\xff"]] as $field => $values) {
            foreach ($values as $index => $value) {
                yield $field . $index => [[$field => $value]];
            }
        }
        yield 'unexpected id' => [['id' => '42']];
        yield 'v3 needs user' => [['snmp_version' => '3']];
        yield 'v3 privacy needs auth' => [['snmp_version' => '3', 'snmp_username' => 'x', 'snmp_priv_protocol' => 'AES']];
        yield 'v3 short passphrase' => [['snmp_version' => '3', 'snmp_username' => 'x', 'snmp_auth_protocol' => 'SHA', 'use_default_credentials' => false, 'snmp_password' => 'short']];
    }

    #[DataProvider('invalidFields')]
    public function testInvalidDataCannotReachPersistence(array $fields): void
    {
        $creator = $this->createMock(DeviceCreator::class);
        $creator->expects(self::never())->method('create');
        $this->expectException(\InvalidArgumentException::class);
        (new CreateDevice($this->access(), $creator))(array_replace(['description' => 'Valid', 'hostname' => 'localhost'], $fields));
    }

    public function testUseCasePassesActorAndValidatedDataToPort(): void
    {
        $creator = $this->createMock(DeviceCreator::class);
        $creator->expects(self::once())->method('create')->with(42, self::callback(static fn(NewDevice $site): bool => $site->fields['description'] === 'Tokyo'))->willReturn(7);
        self::assertSame(7, (new CreateDevice($this->access(), $creator))(['description' => ' Tokyo ', 'hostname' => 'localhost']));
    }

    public function testUnauthorizedCreationDoesNotReachPort(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(null);
        $creator = $this->createMock(DeviceCreator::class);
        $creator->expects(self::never())->method('create');
        $this->expectException(InventoryAccessDenied::class);
        (new CreateDevice($access, $creator))(['description' => 'Denied', 'hostname' => 'localhost']);
    }

    private function access(): ConsoleAccess
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);

        return $access;
    }

    public static function requests(): iterable
    {
        yield 'success' => ['success', 303];
        yield 'extra field' => ['extra', 422];
        yield 'invalid name' => ['invalid', 422];
        yield 'secret is not reflected' => ['secret', 422];
        yield 'invalid reference' => ['reference', 422];
        yield 'missing csrf' => ['csrf', 422];
        yield 'revoked at persistence' => ['revoked', 403];
        yield 'uncertain write' => ['failure', 502];
    }

    #[DataProvider('requests')]
    public function testSymfonyFormRoutesOnlyValidatedAuthorizedPosts(string $mode, int $expected): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->set(ConsoleAccess::class, $this->access());
            $preference = $this->createMock(\Kadupul\IdentityAccess\Contract\LocalePreference::class);
            $preference->method('preferredLocale')->willReturn('fr');
            $container->set(\Kadupul\IdentityAccess\Contract\LocalePreference::class, $preference);
            $configuration = $this->createMock(\Kadupul\Platform\Contract\LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['forced_locale' => 'fr']);
            $container->set(\Kadupul\Platform\Contract\LegacyConfiguration::class, $configuration);
            $db = new \PDO('sqlite::memory:');
            $db->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database = $this->createMock(\Kadupul\Platform\Contract\DatabaseConnection::class);
            $database->method('get')->willReturn($db);
            $container->set(\Kadupul\Platform\Contract\DatabaseConnection::class, $database);
            $catalog = $this->createMock(\Kadupul\Inventory\Application\Port\DeviceCreationCatalog::class);
            $defaults = array_replace(NewDevice::DEFAULTS, ['host_template_id' => 0, 'site_id' => 0, 'poller_id' => 1]);
            $catalog->method('choices')->willReturn(new \Kadupul\Inventory\Application\Query\DeviceCreationChoices($defaults, [2 => 'Template'], [3 => 'Site'], [1 => 'Main']));
            $container->set(\Kadupul\Inventory\Application\Port\DeviceCreationCatalog::class, $catalog);
            $creator = $this->createMock(DeviceCreator::class);
            if ($mode === 'revoked') {
                $creator->expects(self::once())->method('create')->willThrowException(new InventoryAccessDenied(false));
            } elseif ($mode === 'failure') {
                $creator->expects(self::once())->method('create')->willThrowException(new \RuntimeException('private-sql-host'));
            } elseif ($mode === 'success') {
                $creator->expects(self::once())->method('create')->with(42, self::callback(static fn(NewDevice $device): bool => !$device->fields['enabled'] && $device->fields['use_default_credentials']))->willReturn(7);
            } else {
                $creator->expects(self::never())->method('create');
            }
            $container->set(DeviceCreator::class, $creator);
            $token = $container->get(CsrfTokenManagerInterface::class)->getToken('inventory_device_create')->getValue();
            $data = array_replace(NewDevice::DEFAULTS, ['description' => $mode === 'invalid' ? '' : 'Tokyo', 'hostname' => '127.0.0.1', 'enabled' => '0', 'use_default_credentials' => '1', '_token' => $token]);
            if ($mode === 'extra') {
                $data['id'] = '99';
            }
            if ($mode === 'secret') {
                $data['snmp_community'] = 'private-submitted-secret';
            }
            if ($mode === 'reference') {
                $data['poller_id'] = '99';
            }
            if ($mode === 'csrf') {
                unset($data['_token']);
            }
            $request = Request::create('/inventory/devices/new', 'POST', ['device_create' => $data], ['Cacti' => 'fixture']);
            if ($mode !== 'csrf') {
                $request->headers->set('Origin', 'http://localhost');
            }
            $response = $kernel->handle($request);
            self::assertSame($expected, $response->getStatusCode(), substr(strip_tags($response->getContent()), 0, 900));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertStringNotContainsString('private-sql-host', $response->getContent());
            self::assertStringNotContainsString('private-submitted-secret', $response->getContent());
            if ($expected === 422) {
                self::assertStringContainsString('<html lang="fr">', $response->getContent());
                self::assertStringContainsString('Créer l’appareil', $response->getContent());
            }
            if ($mode === 'success') {
                self::assertStringContainsString('/inventory/devices?', $response->headers->get('Location'));
            }
        } finally {
            $kernel->shutdown();
        }
    }
    public function testCatalogNeverLoadsSecretsAndFallsBackFromMissingReferences(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->exec("CREATE TABLE settings (name TEXT,value TEXT); CREATE TABLE host_template (id INTEGER,name TEXT); CREATE TABLE sites (id INTEGER,name TEXT); CREATE TABLE poller (id INTEGER,name TEXT,disabled TEXT);
            INSERT INTO settings VALUES ('snmp_community','private-fixture'),('snmp_password','private-fixture'),('snmp_priv_passphrase','private-fixture'),('default_template','99'),('default_site','3'),('default_poller','9'),('snmp_version','1');
            INSERT INTO sites VALUES (3,'Tokyo'); INSERT INTO poller VALUES (1,'Main',''),(9,'Disabled','on'); INSERT INTO host_template VALUES (2,'Template');");
        $database = $this->createMock(\Kadupul\Platform\Contract\DatabaseConnection::class);
        $database->method('get')->willReturn($db);
        $choices = (new \Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceCreationCatalog($database))->choices();
        self::assertSame(0, $choices->defaults['host_template_id']);
        self::assertSame(3, $choices->defaults['site_id']);
        self::assertSame(1, $choices->defaults['poller_id']);
        self::assertSame('1', $choices->defaults['snmp_version']);
        self::assertSame([1 => 'Main'], $choices->pollers);
        self::assertStringNotContainsString('private-fixture', json_encode($choices));
    }

    public function testUnauthorizedPreparationDoesNotReadCatalog(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(null);
        $catalog = $this->createMock(\Kadupul\Inventory\Application\Port\DeviceCreationCatalog::class);
        $catalog->expects(self::never())->method('choices');
        $this->expectException(InventoryAccessDenied::class);
        (new \Kadupul\Inventory\Application\Query\PrepareDeviceCreation($access, $catalog))();
    }

    public static function workerResults(): iterable
    {
        yield 'lost acknowledgement' => ['', 1, \RuntimeException::class];
        yield 'malformed acknowledgement' => ['KADUPUL_CREATE_RESULT={broken}', 0, \RuntimeException::class];
        yield 'nonzero with success' => ['KADUPUL_CREATE_RESULT={"status":"ok","id":7}', 1, \RuntimeException::class];
        yield 'denied' => ['KADUPUL_CREATE_RESULT={"status":"denied"}', 1, InventoryAccessDenied::class];
        yield 'invalid references' => ['KADUPUL_CREATE_RESULT={"status":"invalid"}', 1, \InvalidArgumentException::class];
    }

    #[DataProvider('workerResults')]
    public function testWorkerFailuresAreSanitizedAndNeverRetried(string $output, int $exit, string $error): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-create-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        file_put_contents($directory . '/bin/legacy-device-create.php', '<?php file_put_contents(__DIR__ . "/calls", "called\\n", FILE_APPEND); fwrite(STDERR, "private-worker-secret"); echo ' . var_export($output, true) . '; exit(' . $exit . ');');
        try {
            $creator = new \Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceCreator($directory);
            try {
                $creator->create(42, new NewDevice(['description' => 'Test', 'hostname' => 'localhost']));
                self::fail('Expected sanitized failure');
            } catch (\Exception $failure) {
                self::assertInstanceOf($error, $failure);
                self::assertStringNotContainsString('private-worker-secret', $failure->getMessage());
            }
            self::assertSame("called\n", file_get_contents($directory . '/bin/calls'));
        } finally {
            unlink($directory . '/bin/calls');
            unlink($directory . '/bin/legacy-device-create.php');
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }

}
