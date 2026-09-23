<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceMaintenance;
use Kadupul\Inventory\Domain\DeviceMaintenanceState;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeviceMaintenancePresentationTest extends TestCase
{
    public static function saveOutcomes(): array
    {
        return [[null, 200], [true, 401], [false, 403], [null, 200, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('saveOutcomes')]
    public function testFrenchPresentationEscapesNamesAndPreservesAssignmentValues(?bool $unauthenticated, int $expectedStatus, bool $missingAfterSave = false): void
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
            $device = new DeviceMaintenanceState(new \Kadupul\Inventory\Domain\DeviceState(7, '<router>', 'router.invalid', true, 0, 1, 0), [2 => '<Query>', 3 => '<Query>'], [], false);
            $port = $this->createMock(DeviceMaintenance::class);
            $saved = false;
            $port->method('findVisible')->willReturnCallback(static function () use (&$saved, $missingAfterSave, $device) {
                return $saved && $missingAfterSave ? null : $device;
            });
            $save = $port->expects(self::once())->method('execute')->with(42, 7, self::callback(fn($request) => $request->operation === 'connectivity'), $device->revision());
            if ($unauthenticated === null) {
                $save->willReturnCallback(static function () use (&$saved) {
                    $saved = true;
                    return new \Kadupul\Inventory\Application\ReadModel\DeviceMaintenanceResult(true, 'Connectivity check finished.', '<unsafe>');
                });
            }
            if ($unauthenticated !== null) {
                $save->willThrowException(new \Kadupul\Inventory\Application\Query\InventoryAccessDenied($unauthenticated));
            }
            $container->set(DeviceMaintenance::class, $port);
            $path = '/inventory/devices/7/maintenance';
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Maintenance de l’appareil', $response->getContent());
            self::assertStringContainsString('&lt;router&gt;', $response->getContent());
            self::assertStringContainsString('value="2">&lt;Query&gt;', $response->getContent());
            self::assertStringContainsString('value="3">&lt;Query&gt;', $response->getContent());
            self::assertStringNotContainsString('value="4"', $response->getContent());
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $token = (new \DOMXPath($document))->evaluate('string(//input[@name="device_maintenance[_token]"]/@value)');
            $fields = ['operation' => 'connectivity', 'query' => '0', 'revision' => $device->revision(), '_token' => $token];
            foreach (['', '9999', null] as $invalid) {
                $data = array_replace($fields, ['query' => $invalid]);
                $request = Request::create($path, 'POST', ['device_maintenance' => $data], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                $response = $kernel->handle($request);
                self::assertSame(422, $response->getStatusCode());
            }
            $request = Request::create($path, 'POST', ['device_maintenance' => $fields], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            $response = $kernel->handle($request);
            self::assertSame($expectedStatus, $response->getStatusCode());
            if ($expectedStatus === 200) {
                self::assertStringContainsString('&lt;unsafe&gt;', $response->getContent());
                self::assertStringNotContainsString('<unsafe>', $response->getContent());
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
