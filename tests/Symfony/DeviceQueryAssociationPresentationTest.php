<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceAssociationStore;
use Kadupul\Inventory\Domain\DeviceAssociations;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeviceQueryAssociationPresentationTest extends TestCase
{
    public static function saveOutcomes(): array
    {
        return [[null, 303], [true, 401], [false, 403]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('saveOutcomes')]
    public function testFrenchPresentationEscapesNamesAndPreservesAssignmentValues(?bool $unauthenticated, int $expectedStatus): void
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
            $device = new DeviceAssociations(7, '<router>', 0, 1, 0, [], [], 'query');
            $port = $this->createMock(DeviceAssociationStore::class);
            $port->method('findVisible')->willReturn($device);
            $port->method('available')->willReturn([1 => 'Primary', 2 => '<Collector>', 3 => '<Collector>']);
            $save = $port->expects(self::once())->method('change')->with(42, 7, self::callback(fn($change) => $change->targetId === 2 && $change->operation === 'add' && $change->reindexMethod === 2), $device->revision());
            if ($unauthenticated !== null) {
                $save->willThrowException(new \Kadupul\Inventory\Application\Query\InventoryAccessDenied($unauthenticated));
            }
            $container->set(DeviceAssociationStore::class, $port);
            $path = '/inventory/devices/7/associations/query';
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Associations de requêtes de données de l’appareil', $response->getContent());
            self::assertStringContainsString('&lt;router&gt;', $response->getContent());
            self::assertStringContainsString('value="2">&lt;Collector&gt;', $response->getContent());
            self::assertStringContainsString('value="3">&lt;Collector&gt;', $response->getContent());
            self::assertStringNotContainsString('value="4"', $response->getContent());
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $token = (new \DOMXPath($document))->evaluate('string(//input[@name="device_association[_token]"]/@value)');
            $fields = ['reindex' => '2', 'operation' => 'add', 'target' => '2', 'revision' => $device->revision(), '_token' => $token];
            foreach (['', '9999', null] as $invalid) {
                $data = array_replace($fields, ['target' => $invalid]);
                $request = Request::create($path, 'POST', ['device_association' => $data], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                $response = $kernel->handle($request);
                self::assertSame(422, $response->getStatusCode());
            }
            $request = Request::create($path, 'POST', ['device_association' => $fields], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            self::assertSame($expectedStatus, $kernel->handle($request)->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}
