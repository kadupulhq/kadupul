<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/DeviceRouteNativeHarness.php';
require_once dirname(__DIR__, 3) . '/Helpers/DeviceRouteCoverageRegistration.php';
require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';
require_once dirname(__DIR__, 3) . '/Helpers/PestCodeCoverageCompatibility.php';

final class DeviceRouteNativeTest extends PHPUnit\Framework\TestCase
{
    use PestCodeCoverageCompatibility;

    #[PHPUnit\Framework\Attributes\DataProvider('legacyCases')]
    public function testActualCompatibilityWrapperExpiresPostsAndNavigatesWithoutWrites(array $fields, string $method, string $access, int $status, ?string $destination): void
    {
        foreach (['', '/cacti'] as $base) {
            $state = DeviceRouteNativeHarness::run(['kind' => 'wrapper', 'fields' => $fields, 'method' => $method, 'access' => $access, 'base' => $base], $this->getTestResultObject()->getCodeCoverage());
            self::assertSame($status, $state['status']);
            self::assertSame($destination === null ? null : $base . '/app.php' . $destination, $state['location']);
            self::assertSame($state['before'], $state['after']);
            self::assertDoesNotMatchRegularExpression('/PHP (?:Warning|Fatal|Notice)/', $state['stderr']);
            if ($method === 'POST' && $access === 'manager') self::assertStringContainsString('legacy form has expired', $state['html']);
        }
    }

    public static function legacyCases(): iterable
    {
        foreach (['ping_host', 'enable_debug', 'disable_debug', 'repopulate', 'query_reload', 'query_verbose', 'reindex'] as $action) {
            foreach ([12, 13] as $id) {
                yield "$action navigation $id" => [['action' => $action, $action === 'ping_host' ? 'id' : 'host_id' => (string) $id], 'GET', 'manager', 302, "/inventory/devices/$id/maintenance"];
            }
        }
        foreach (['query_add', 'query_remove', 'query_change', 'gt_add', 'gt_remove'] as $action) {
            $kind = str_starts_with($action, 'gt_') ? 'graph' : 'query';
            yield "$action navigation" => [['action' => $action, 'host_id' => '12'], 'GET', 'manager', 302, '/inventory/devices/12/associations/' . $kind];
        }
        foreach (['save', 'actions', 'ping_host', 'enable_debug', 'disable_debug', 'repopulate', 'query_add', 'query_reload', 'query_verbose', 'query_remove', 'query_change', 'gt_add', 'gt_remove'] as $action) {
            yield "$action expired" => [['action' => $action, 'id' => '13', 'selected_items' => serialize([12, 13])], 'POST', 'manager', 409, null];
        }
        foreach (['1', '2', '3', '4', '5', '6', '7', '8', 'tr_20', 'plugin'] as $mode) {
            yield "bulk $mode expired" => [['action' => 'actions', 'drp_action' => $mode, 'selected_items' => serialize([12, 13])], 'POST', 'manager', 409, null];
        }
        foreach (['0', ''] as $id) yield "edit create $id" => [['action' => 'edit', 'id' => $id], 'GET', 'manager', 302, '/inventory/devices/new'];
        foreach (['12', '13'] as $id) yield "edit navigation $id" => [['action' => 'edit', 'id' => $id], 'GET', 'manager', 302, "/inventory/devices/$id/edit"];
        foreach (['0', '-1', '', ['12']] as $id) yield 'invalid maintenance ' . json_encode($id) => [['action' => 'repopulate', 'host_id' => $id], 'GET', 'manager', 400, null];
        foreach (['anonymous' => 401, 'unprivileged' => 403] as $access => $status) {
            foreach (['GET', 'POST'] as $method) yield "$access $method before malformed fields" => [['action' => ['edit'], 'id' => ['13']], $method, $access, $status, null];
        }
        yield 'legacy bulk selection expires' => [['action' => 'actions', 'drp_action' => 'native_budget_probe', 'selected_items' => serialize(range(1001, 6000))], 'POST', 'manager', 409, null];
        yield 'malformed expired payload' => [['action' => ['save'], 'id' => ['13']], 'POST', 'manager', 409, null];
    }

    public function testInstalledDestinationFormsEnforceCurrentAuthorizationValidationAndActionHandoffs(): void
    {
        $state = DeviceRouteNativeHarness::run(['kind' => 'destinations'], $this->getTestResultObject()->getCodeCoverage());
        self::assertSame(0, $state['exit']);
        self::assertSame(124, $state['tests']);
        self::assertSame(1198, $state['assertions']);
        self::assertDoesNotMatchRegularExpression('/PHP (?:Warning|Fatal|Notice)/', $state['stderr']);
    }
}
