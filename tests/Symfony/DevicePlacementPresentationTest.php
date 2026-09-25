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
use Kadupul\Inventory\Application\Port\DevicePlacements;
use Kadupul\Graphing\Contract\DeviceTreePlacement;
use Kadupul\Reporting\Contract\DeviceReportPlacement;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DevicePlacementPresentationTest extends TestCase
{
    public static function kinds(): iterable
    {
        foreach ([['tree', '2:0'], ['report', '3']] as [$kind, $destination]) {
            foreach (['javascript:alert(1)', '//evil.invalid/path', '\" onmouseover=\"alert(1)<svg>'] as $search) {
                yield [$kind, $destination, $search];
            }
        }
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function testFrenchPageValidatesChoicesAndSubmitsToUseCase(string $kind, string $destination, string $search): void
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
            $database = $this->createMock(\Kadupul\Platform\Contract\DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $container->set(\Kadupul\Platform\Contract\DatabaseConnection::class, $database);
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $container->set(ConsoleAccess::class, $access);
            $device = new DeviceState(7, '<router>', 'router.invalid', true, 0, 1, 0);
            $states = $this->createMock(DeviceStates::class);
            $states->method('findVisible')->willReturn([$device]);
            $container->set(DeviceStates::class, $states);
            foreach ([DeviceTreePlacement::class,DeviceReportPlacement::class] as $contract) {
                $catalog = $this->createMock($contract);
                if ($contract === DeviceReportPlacement::class) {
                    $catalog->method('defaultTimespan')->willReturn(11);
                }
                $catalog->method('destinations')->willReturn([$destination => '<destination>']);
                $container->set($contract, $catalog);
            }
            $port = $this->createMock(DevicePlacements::class);
            $port->expects(self::once())->method('place')->with(42, self::callback(fn($s) => $s->revisions === [7 => $device->revision()]), self::callback(fn($p) => $p->kind === $kind && $p->destination === $destination));
            $container->set(DevicePlacements::class, $port);
            $path = '/inventory/devices/place/' . $kind . '?ids[]=7&' . http_build_query(['list' => ['q' => $search]]);
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Ajouter les appareils', $response->getContent());
            self::assertStringContainsString('&lt;router&gt;', $response->getContent());
            self::assertStringContainsString('&lt;destination&gt;', $response->getContent());
            $doc = new \DOMDocument();
            @$doc->loadHTML($response->getContent());
            $links = (new \DOMXPath($doc))->query('//a');
            self::assertCount(1, $links);
            $href = $links->item(0)->getAttribute('href');
            self::assertSame('/inventory/devices', parse_url($href, PHP_URL_PATH));
            self::assertNull(parse_url($href, PHP_URL_SCHEME));
            self::assertNull(parse_url($href, PHP_URL_HOST));
            parse_str(parse_url($href, PHP_URL_QUERY), $linkQuery);
            self::assertSame($search, $linkQuery['q']);
            self::assertFalse($links->item(0)->hasAttribute('onmouseover'));
            if ($kind === 'report') {
                self::assertSame('11', (new \DOMXPath($doc))->evaluate('string(//select[@name="device_placement[timespan]"]/option[@selected]/@value)'));
                self::assertSame('2', (new \DOMXPath($doc))->evaluate('string(//select[@name="device_placement[alignment]"]/option[@selected]/@value)'));
            }
            $token = (new \DOMXPath($doc))->evaluate('string(//input[@name="device_placement[_token]"]/@value)');
            $fields = ['selection' => json_encode([7 => $device->revision()]),'target' => $destination,'_token' => $token];
            if ($kind === 'report') {
                $fields += ['timespan' => '7','alignment' => '2'];
            }
            foreach ([['target' => '99999'],['target' => ['2:0']],['poller_id' => '2']] as $invalid) {
                $request = Request::create($path, 'POST', ['device_placement' => array_replace($fields, $invalid)], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                $invalidResponse = $kernel->handle($request);
                self::assertSame(422, $invalidResponse->getStatusCode());
                self::assertStringContainsString('Sélectionnez une destination valide.', $invalidResponse->getContent());
            }
            $request = Request::create($path, 'POST', ['device_placement' => $fields], ['Cacti' => 'fixture']);
            $request->headers->set('Origin', 'http://localhost');
            self::assertSame(303, $kernel->handle($request)->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}
