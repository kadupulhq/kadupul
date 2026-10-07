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
use Kadupul\Inventory\Application\Port\DeviceAssociationStore;
use Kadupul\Inventory\Application\Port\DeviceMaintenance;
use Kadupul\Inventory\Application\ReadModel\DeviceMaintenanceResult;
use Kadupul\Inventory\Domain\DeviceAssociationChange;
use Kadupul\Inventory\Domain\DeviceAssociations;
use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
use Kadupul\Inventory\Domain\DeviceMaintenanceState;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeviceActionCsrfTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function actions(): iterable
    {
        foreach (['graph' => ['add', 'remove'], 'query' => ['add', 'remove', 'change']] as $kind => $operations) {
            foreach ($operations as $operation) {
                yield "$kind $operation" => [$kind, $operation];
            }
        }
        // LegacyDevicesController redirects reindex/query_reload/query_verbose/ping_host/
        // enable_debug/disable_debug/repopulate to these seven rendered actions.
        foreach (['reindex', 'reload-query', 'query-diagnostics', 'refresh-cache', 'enable-debug', 'disable-debug', 'connectivity'] as $operation) {
            yield "maintenance $operation" => ['maintenance', $operation];
        }
    }

    #[DataProvider('actions')]
    public function testRealFormsRejectCsrfFailuresBeforeMutationAndAdmitTheSameCompleteAction(string $kind, string $operation): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['forced_locale' => 'en-US']);
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
            $mutations = 0;

            if ($kind === 'maintenance') {
                $state = new DeviceMaintenanceState(new DeviceState(7, 'Router', 'router.invalid', true, 0, 1, 0), [2 => 'Query'], [2 => 3], false);
                $query = in_array($operation, ['reload-query', 'query-diagnostics'], true) ? 2 : 0;
                $port = $this->createMock(DeviceMaintenance::class);
                $port->method('findVisible')->with(42, 7)->willReturn($state);
                $port->expects(self::once())->method('execute')->with(42, 7, self::callback(static fn(DeviceMaintenanceRequest $request): bool => $request->operation === $operation && $request->queryId === $query), $state->revision(), $state)
                    ->willReturnCallback(static function () use (&$mutations): DeviceMaintenanceResult {
                        ++$mutations;
                        return new DeviceMaintenanceResult(true, 'Completed.', '');
                    });
                $container->set(DeviceMaintenance::class, $port);
                $path = '/inventory/devices/7/maintenance';
                $name = 'device_maintenance';
                $fields = ['operation' => $operation, 'query' => (string) $query, 'revision' => $state->revision()];
                $admittedStatus = 200;
            } else {
                $state = new DeviceAssociations(7, 'Router', 0, 1, 0, [2 => 'Existing'], [2 => 3], $kind);
                $target = $operation === 'add' ? 3 : 2;
                $port = $this->createMock(DeviceAssociationStore::class);
                $port->method('findVisible')->with(42, 7, $kind)->willReturn($state);
                $port->method('available')->willReturn([3 => 'Available']);
                $port->method('defaultReindexMethod')->willReturn(3);
                $port->expects(self::once())->method('change')->with(42, 7, self::callback(static fn(DeviceAssociationChange $change): bool => $change->kind === $kind && $change->operation === $operation && $change->targetId === $target && $change->reindexMethod === ($kind === 'query' ? 2 : 0)), $state->revision())
                    ->willReturnCallback(static function () use (&$mutations): void {
                        ++$mutations;
                    });
                $container->set(DeviceAssociationStore::class, $port);
                $path = '/inventory/devices/7/associations/' . $kind;
                $name = 'device_association';
                $fields = ['operation' => $operation, 'target' => (string) $target, 'revision' => $state->revision()];
                if ($kind === 'query') {
                    $fields['reindex'] = '2';
                }
                $admittedStatus = 303;
            }

            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $token = $xpath->evaluate('string(//input[@name="' . $name . '[_token]"]/@value)');
            self::assertNotSame('', $token);
            self::assertSame($state->revision(), $xpath->evaluate('string(//input[@name="' . $name . '[revision]"]/@value)'));
            self::assertCount(1, $xpath->query('//select[@name="' . $name . '[operation]"]/option[@value="' . $operation . '"]'));

            // Stateless CSRF requires trustworthy origin or matching double-submit evidence.
            // The short malformed token is independently rejected even with the correct origin.
            foreach ([
                'foreign origin' => ['https://foreign.invalid', $token],
                'missing token' => ['http://localhost', null],
                'malformed token' => ['http://localhost', 'invalid'],
                'missing origin and double-submit' => [null, $token],
            ] as $case => [$origin, $submittedToken]) {
                $data = $fields;
                if ($submittedToken !== null) {
                    $data['_token'] = $submittedToken;
                }
                $request = Request::create($path, 'POST', [$name => $data], ['Cacti' => 'fixture']);
                if ($origin !== null) {
                    $request->headers->set('Origin', $origin);
                }
                $denied = $kernel->handle($request);
                self::assertSame(422, $denied->getStatusCode(), $case);
                self::assertStringContainsString('The CSRF token is invalid.', $denied->getContent(), $case);
                self::assertSame(0, $mutations, $case);
            }

            $request = Request::create($path, 'POST', [$name => $fields + ['_token' => $token]], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            self::assertSame($admittedStatus, $kernel->handle($request)->getStatusCode());
            self::assertSame(1, $mutations);
        } finally {
            $kernel->shutdown();
        }
    }
}
