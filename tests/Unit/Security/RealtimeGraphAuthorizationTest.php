<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

final class RealtimeGraphAuthorizationTest extends TestCase
{
    public function testRealmAndGraphPermissionsAreCheckedBeforePollingOrCacheRead(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../graph_realtime.php');
        self::assertIsString($source);

        $realmCheck = strpos($source, 'if (!is_realm_allowed(25))');
        $pollerCall = strpos($source, 'cacti_exec(read_config_option(\'path_php_binary\')');
        self::assertNotFalse($realmCheck);
        self::assertNotFalse($pollerCall);
        self::assertLessThan($pollerCall, $realmCheck);

        $pollGraphCheck = strpos($source, '!is_graph_allowed($local_graph_id, $user_id)');
        $viewAction = strpos($source, "case 'view':");
        $viewGraphCheck = strrpos($source, '!is_graph_allowed($local_graph_id, $user_id)');
        $cacheRead = strpos($source, 'if (file_exists($graph_rrd))', $viewAction);
        self::assertNotFalse($viewAction);
        self::assertNotFalse($pollGraphCheck);
        self::assertNotFalse($viewGraphCheck);
        self::assertNotFalse($cacheRead);
        self::assertLessThan($pollerCall, $pollGraphCheck);
        self::assertLessThan($cacheRead, $viewGraphCheck);
        self::assertStringContainsString('$user_id < 1 ||', $source);
    }
}
