<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$applicationLoader = require dirname(__DIR__, 3) . '/include/vendor/autoload.php';
// Keep the isolated Pest runner dependency versions ahead of application dev tools.
$applicationLoader->unregister();
$applicationLoader->register(false);
// Maintenance POST/CSRF/worker behavior is covered by the Symfony HTTP suite.
test('legacy device actions never replay writes and validate navigation identifiers', function ($method, $id, $action, $status) {
    $access = $this->createMock(\Kadupul\IdentityAccess\Contract\ConsoleAccess::class);
    $access->method('consoleActor')->willReturn(new \Kadupul\IdentityAccess\Contract\Actor(42, 'operator'));
    $access->method('canManageDevices')->willReturn(true);
    $store = $this->createMock(\Kadupul\Inventory\Application\Port\DeviceLocations::class);
    $store->expects($this->never())->method('matching');
    $locations = new \Kadupul\Inventory\Application\Query\SuggestDeviceLocations($access, $store);
    $urls = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
    if ($status === 302) {
        $urls->expects($this->once())->method('generate')->with('inventory_device_maintenance', ['id' => 7])->willReturn('/inventory/devices/7/maintenance');
    } else {
        $urls->expects($this->never())->method('generate');
    }
    $request = \Symfony\Component\HttpFoundation\Request::create('/inventory/devices/legacy', $method, ['action' => $action, 'host_id' => $id, '__csrf_magic' => 'old-token']);
    $response = (new \Kadupul\Inventory\Infrastructure\Symfony\Controller\LegacyDevicesController())($request, $access, $locations, $urls, new \Symfony\Component\Translation\Translator('en'));
    expect($response->getStatusCode())->toBe($status);
    if ($status === 302) {
        expect($response->headers->get('Location'))->toBe('/inventory/devices/7/maintenance');
    }
})->with([
    ['GET', '7', 'reindex', 302], ['HEAD', '7', 'reindex', 302],
    ['GET', '0', 'reindex', 400], ['GET', '-7', 'reindex', 400],
    ['GET', null, 'reindex', 400], ['GET', ['7'], 'reindex', 400],
    ['GET', '7;id', 'reindex', 400], ['GET', '7', ['reindex'], 400],
    ['POST', '7', 'reindex', 409], ['POST', '7;id', 'reindex', 409],
    ['POST', ['7'], ['actions'], 409], ['POST', '7', 'actions', 409],
]);
