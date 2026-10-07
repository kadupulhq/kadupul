<?php

declare(strict_types=1);

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

final class DeviceAutomationPresentationTest extends TestCase
{
    public static function writeOutcomes(): array
    {
        return [
            'success' => [null, 303, null],
            'unauthenticated write' => [new \Kadupul\Inventory\Application\Query\InventoryAccessDenied(true), 401, null],
            'unauthorized write' => [new \Kadupul\Inventory\Application\Query\InventoryAccessDenied(false), 403, null],
            'missing selection' => [new \Kadupul\Inventory\Application\Command\DevicesNotFound(), 404, null],
            'stale selection' => [new \Kadupul\Inventory\Domain\DeviceEditConflict('Stale revision'), 409, 'Stale revision'],
            'invalid operation' => [new \InvalidArgumentException('Invalid operation'), 422, 'Invalid operation'],
            'uncertain worker' => [new \RuntimeException('private worker diagnostic'), 502, 'Le résultat de l’opération est incertain.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writeOutcomes')]
    public function testFrenchPresentationEscapesNamesAndPreservesAssignmentValues(\RuntimeException|\InvalidArgumentException|null $failure, int $expectedStatus, ?string $expectedMessage): void
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
            $port = $this->createMockForIntersectionOfInterfaces([\Kadupul\Inventory\Application\Port\DeviceAutomation::class, DeviceStates::class, \Kadupul\Inventory\Application\Port\DeviceSnmpSettings::class, \Kadupul\Inventory\Application\Port\DeviceStatistics::class, \Kadupul\Inventory\Application\Port\DeviceTemplateSynchronization::class, \Kadupul\Inventory\Application\Port\DeviceOptions::class]);
            $port->method('findVisible')->willReturn([$device]);
            $save = $port->expects(self::once())->method('applyRules')->with(42, self::callback(fn($selection) => $selection->revisions === [7 => $device->revision()]));
            if ($failure !== null) {
                $save->willThrowException($failure);
            }
            $container->set(\Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceStates::class, $port);
            $path = '/inventory/devices/automation?ids[]=7';
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Appliquer les règles', $response->getContent());
            self::assertStringContainsString('&lt;router&gt;', $response->getContent());
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $token = (new \DOMXPath($document))->evaluate('string(//input[@name="device_state[_token]"]/@value)');
            $fields = ['selection' => json_encode([7 => $device->revision()]), '_token' => $token];
            foreach (['', 'null', '{}', '{"8":"' . $device->revision() . '"}'] as $invalid) {
                $request = Request::create($path, 'POST', ['device_state' => array_replace($fields, ['selection' => $invalid])], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                self::assertSame(422, $kernel->handle($request)->getStatusCode());
            }
            $request = Request::create($path, 'POST', ['device_state' => $fields], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            $response = $kernel->handle($request);
            self::assertSame($expectedStatus, $response->getStatusCode());
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            self::assertStringNotContainsString('private worker diagnostic', $response->getContent());
            if ($expectedMessage !== null) {
                self::assertStringContainsString($expectedMessage, $response->getContent());
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
