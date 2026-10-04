<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceLocations;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LegacyDevicesTest extends TestCase
{
    public function testCompatibilityLinksNeverReplayMutations(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $config = $this->createMock(LegacyConfiguration::class);
            $config->method('values')->willReturn(['forced_locale' => 'fr-FR']);
            $container->set(LegacyConfiguration::class, $config);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT,value TEXT)');
            $db = $this->createMock(DatabaseConnection::class);
            $db->method('get')->willReturn($pdo);
            $container->set(DatabaseConnection::class, $db);
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $container->set(ConsoleAccess::class, $access);
            $locations = $this->createMock(DeviceLocations::class);
            $locations->expects(self::once())->method('matching')->with(42, 'rack')->willReturn(['rack <A>']);
            $container->set(DeviceLocations::class, $locations);
            $base = '/inventory/devices/legacy';
            foreach (['?action=edit' => '/inventory/devices/new','?action=edit&host_template_id=2' => '/inventory/devices/new?host_template_id=2','?action=edit&host_template_id=0' => '/inventory/devices/new?host_template_id=0','?action=edit&id=7' => '/inventory/devices/7/edit','?action=gt_remove&host_id=7&id=2' => '/inventory/devices/7/associations/graph','?action=query_add&host_id=7' => '/inventory/devices/7/associations/query','?action=repopulate&host_id=7' => '/inventory/devices/7/maintenance','?action=ping_host&id=7' => '/inventory/devices/7/maintenance'] as $query => $target) {
                $response = $kernel->handle(Request::create($base . $query));
                self::assertSame(302, $response->getStatusCode());
                self::assertSame($target, $response->headers->get('Location'));
                self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            }
            $response = $kernel->handle(Request::create($base . '?filter=rack&site_id=0&host_template_id=2&poller_id=3&location=&host_status=-4'));
            self::assertSame(302, $response->getStatusCode());
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $filters);
            self::assertSame('2', $filters['template']);
            self::assertSame('3', $filters['collector']);
            self::assertSame('exact', $filters['location_mode']);
            self::assertSame('not-up', $filters['status']);
            foreach (['Undefined', 'Indéfini', 'Undefiniert', '未定义', ''] as $undefined) {
                $response = $kernel->handle(Request::create($base . '?location=' . rawurlencode($undefined)));
                self::assertSame(302, $response->getStatusCode());
                parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $filters);
                self::assertSame('exact', $filters['location_mode']);
                self::assertSame('', $filters['location']);
            }
            $response = $kernel->handle(Request::create($base . '?location=' . rawurlencode('Rack Undefined')));
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $filters);
            self::assertSame('Rack Undefined', $filters['location']);

            foreach (['10' => '25', '30' => '50', '100' => '100', '250' => '100', '5000' => '100'] as $rows => $size) {
                $response = $kernel->handle(Request::create($base . '?rows=' . $rows));
                self::assertSame(302, $response->getStatusCode());
                parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $filters);
                self::assertSame($size, $filters['size']);
            }
            foreach (['0', '-2', 'garbage', '10000000000000000000'] as $rows) {
                self::assertSame(400, $kernel->handle(Request::create($base . '?rows=' . $rows))->getStatusCode());
            }
            foreach (['?action=edit&host_template_id[]=2','?action=edit&host_template_id=-1','?action=edit&host_template_id=bogus','?action=edit&id[]=7','?action=edit&id=-1','?host_status=invalid','?host_template_id=999999999','?location[]=rack'] as $query) {
                self::assertSame(400, $kernel->handle(Request::create($base . $query))->getStatusCode());
            }
            $response = $kernel->handle(Request::create($base . '?action=save', 'POST', ['id' => '7','description' => 'overwrite'], ['Cacti' => 'fixture']));
            self::assertSame(409, $response->getStatusCode());
            self::assertStringContainsString('ancien formulaire a expiré', $response->getContent());
            self::assertSame(405, $kernel->handle(Request::create($base . '?action=save'))->getStatusCode());
            foreach ([str_repeat('x', 201), "\xff", "bad\0term"] as $invalidTerm) {
                $response = $kernel->handle(Request::create($base . '?action=ajax_locations&term=' . rawurlencode($invalidTerm), 'GET', [], ['Cacti' => 'fixture']));
                self::assertSame(400, $response->getStatusCode());
                self::assertSame('Recherche de localisation invalide.', $response->getContent());
            }
            $response = $kernel->handle(Request::create($base . '?action=ajax_locations&term=rack'));
            self::assertSame([['label' => 'rack <A>','value' => 'rack <A>']], json_decode($response->getContent(), true));
        } finally {
            $kernel->shutdown();
        }
    }
}
