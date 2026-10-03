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

final class DeviceOptionsPresentationTest extends TestCase
{
    public function testFrenchPresentationEscapesNamesAndPreservesAssignmentValues(): void
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
            $changes = [];
            $port->expects(self::exactly(3))->method('changeOptions')->with(42, self::callback(fn($selection) => $selection->revisions === [7 => $device->revision()]), self::anything())->willReturnCallback(static function ($actor, $selection, $change) use (&$changes): void {
                $changes[] = $change->fields;
            });
            $container->set(DeviceStates::class, $port);
            $path = '/inventory/devices/options?ids[]=7';
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Modifier les options des appareils', $response->getContent());
            self::assertStringContainsString('&lt;router&gt;', $response->getContent());
            $document = new \DOMDocument();
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            self::assertSame(0, $xpath->query('//*[starts-with(@name, "device_state[options][") and @required]')->length);
            $token = (new \DOMXPath($document))->evaluate('string(//input[@name="device_state[_token]"]/@value)');
            $fields = ['selection' => json_encode([7 => $device->revision()]), '_token' => $token, 'options' => ['apply_location' => '1', 'location' => 'Rack']];
            foreach (['', 'null', '{}', '{"8":"' . $device->revision() . '"}'] as $invalid) {
                $request = Request::create($path, 'POST', ['device_state' => array_replace($fields, ['selection' => $invalid])], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                self::assertSame(422, $kernel->handle($request)->getStatusCode());
            }
            foreach ([[], ['apply_location' => '1', 'location' => 'Rack', 'poller_id' => '2'], ['apply_snmp_timeout' => '1', 'snmp_timeout' => '0']] as $options) {
                $request = Request::create($path, 'POST', ['device_state' => array_replace($fields, ['options' => $options])], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                self::assertSame(422, $kernel->handle($request)->getStatusCode());
            }
            foreach ([
                [[], 'Sélectionnez au moins une option prise en charge.'],
                [['apply_location' => '1', 'location' => str_repeat('🌏', 41)], 'L’emplacement doit être un texte valide de 40 caractères au maximum.'],
                [['apply_location' => '1', 'location' => "\xC3\x28"], 'L’emplacement doit être un texte valide de 40 caractères au maximum.'],
                [['apply_location' => '1', 'location' => "bad\0value"], 'L’emplacement doit être un texte valide de 40 caractères au maximum.'],
                [['apply_snmp_timeout' => '1', 'snmp_timeout' => '0'], 'Un paramètre numérique de l’appareil est hors de la plage autorisée.'],
                [['apply_ping_method' => '1', 'ping_method' => '4'], 'Sélectionnez une option valide pour l’appareil.'],
                [['apply_availability_method' => '1', 'availability_method' => '999'], 'Sélectionnez une option valide pour l’appareil.'],
            ] as [$options, $message]) {
                $request = Request::create($path, 'POST', ['device_state' => array_replace($fields, ['options' => $options])], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                $invalid = $kernel->handle($request);
                self::assertSame(422, $invalid->getStatusCode());
                $document = new \DOMDocument();
                @$document->loadHTML($invalid->getContent());
                self::assertSame(1, substr_count($document->textContent, $message));
                self::assertStringNotContainsString('Sélection d’appareils invalide.', $document->textContent);
            }
            $request = Request::create($path, 'POST', ['device_state' => $fields], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            $redirect = $kernel->handle($request);
            self::assertSame(303, $redirect->getStatusCode());
            foreach ([
                ['apply_availability_method' => '1', 'availability_method' => '6', 'apply_ping_method' => '1', 'ping_method' => '3'],
                ['apply_location' => '1', 'location' => 'Rack', 'availability_method' => '999', 'ping_method' => '4'],
            ] as $options) {
                $request = Request::create($path, 'POST', ['device_state' => array_replace($fields, ['options' => $options])], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                self::assertSame(303, $kernel->handle($request)->getStatusCode());
            }
            self::assertSame([['location' => 'Rack'], ['availability_method' => '6', 'ping_method' => '3'], ['location' => 'Rack']], $changes);
            $catalog = $this->createMock(\Kadupul\Inventory\Application\Port\DeviceCatalog::class);
            $catalog->method('visibleTo')->willReturn(new \Kadupul\Inventory\Application\ReadModel\DevicePage([], false));
            $container->set(\Kadupul\Inventory\Application\Port\DeviceCatalog::class, $catalog);
            $sites = $this->createMock(\Kadupul\Inventory\Application\Port\DeviceSites::class);
            $sites->method('visibleTo')->willReturn([]);
            $container->set(\Kadupul\Inventory\Application\Port\DeviceSites::class, $sites);
            $completed = $kernel->handle(Request::create($redirect->headers->get('Location'), 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $completed->getStatusCode());
            self::assertSame(1, substr_count($completed->getContent(), 'Options des appareils sélectionnés mises à jour.'));
        } finally {
            $kernel->shutdown();
        }
    }
}
