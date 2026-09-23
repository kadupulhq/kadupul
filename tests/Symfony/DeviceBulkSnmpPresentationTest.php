<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceStates;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeviceBulkSnmpPresentationTest extends TestCase
{
    public static function outcomes(): array
    {
        return [[false], [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outcomes')]
    public function testFrenchPresentationEscapesNamesAndPreservesSnmpValues(bool $incompatible): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['forced_locale' => 'fr-FR']);
            $container->set(LegacyConfiguration::class, $configuration);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $container->set(DatabaseConnection::class, $database);
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $container->set(ConsoleAccess::class, $access);
            $device = new DeviceState(7, '<router>', 'router.invalid', true, 0, 1, 0);
            $port = $this->createMockForIntersectionOfInterfaces([DeviceStates::class, \Kadupul\Inventory\Application\Port\DeviceSnmpSettings::class, \Kadupul\Inventory\Application\Port\DeviceStatistics::class, \Kadupul\Inventory\Application\Port\DeviceTemplateSynchronization::class, \Kadupul\Inventory\Application\Port\DeviceOptions::class]);
            $port->method('findVisible')->willReturn([$device]);
            $port->expects(self::once())->method('changeSnmp')->with(42, self::callback(fn($selection) => $selection->revisions === [7 => $device->revision()]), self::callback(fn($change) => $change->fields['keep_credentials'] === false && $change->fields['snmp_community'] === 'fixture-bulk-secret'));
            if ($incompatible) {
                $port->method('changeSnmp')->willThrowException(new \InvalidArgumentException('SNMP settings and stored credentials are incompatible. Replace credentials or review the selected settings.'));
            }
            $container->set(DeviceStates::class, $port);
            $path = '/inventory/devices/snmp?ids[]=7';
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Modifier les paramètres SNMP des appareils', $response->getContent());
            self::assertStringContainsString('&lt;router&gt;', $response->getContent());
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $token = (new \DOMXPath($document))->evaluate('string(//input[@name="device_state[_token]"]/@value)');
            $fields = ['selection' => json_encode([7 => $device->revision()]), '_token' => $token, 'snmp' => ['keep_credentials' => 'replace', 'snmp_community' => 'fixture-bulk-secret'] + \Kadupul\Inventory\Domain\DeviceSnmpConfiguration::PUBLIC_DEFAULTS + \Kadupul\Inventory\Domain\DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS];
            foreach (['', 'null', '{}', '{"8":"' . $device->revision() . '"}'] as $invalid) {
                $request = Request::create($path, 'POST', ['device_state' => array_replace($fields, ['selection' => $invalid])], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                self::assertSame(422, $kernel->handle($request)->getStatusCode());
            }
            foreach ([['poller_id' => '2'], ['snmp_version' => '99'], ['keep_credentials' => 'keep']] as $extra) {
                $request = Request::create($path, 'POST', ['device_state' => array_replace($fields, ['snmp' => array_replace($fields['snmp'], $extra)])], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                $response = $kernel->handle($request);
                self::assertSame(422, $response->getStatusCode());
                self::assertStringNotContainsString('fixture-bulk-secret', $response->getContent());
            }
            $request = Request::create($path, 'POST', ['device_state' => $fields], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            $response = $kernel->handle($request);
            self::assertSame($incompatible ? 422 : 303, $response->getStatusCode());
            if ($incompatible) {
                self::assertStringContainsString('Les paramètres SNMP', $response->getContent());
                self::assertStringNotContainsString('SNMP settings and stored credentials', $response->getContent());
                self::assertStringNotContainsString('fixture-bulk-secret', $response->getContent());
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
