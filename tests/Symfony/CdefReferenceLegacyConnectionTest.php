<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/cdef_reference.php';

final class CdefReferenceLegacyConnectionTest extends TestCase
{
    public function testOnlyCanonicalPrimaryRoleUsesTheExactSelectedConnection(): void
    {
        $names = ['config', 'database_sessions', 'database_hostname', 'database_port', 'database_default'];
        $saved = array_intersect_key($GLOBALS, array_flip($names));
        try {
            $database = new \PDO('sqlite::memory:');
            $GLOBALS['database_hostname'] = 'fixture';
            $GLOBALS['database_port'] = 1;
            $GLOBALS['database_default'] = 'selected';
            $GLOBALS['database_sessions'] = ['fixture:1:selected' => $database];
            foreach ([1, '1'] as $role) {
                $GLOBALS['config'] = ['poller_id' => $role];
                self::assertSame($database, \cdef_reference_primary_connection());
            }
            foreach ([null, false, true, '01', 1.0, 2, '2', '1junk'] as $role) {
                $GLOBALS['config'] = ['poller_id' => $role];
                try {
                    \cdef_reference_primary_connection();
                    self::fail('An unestablished primary role was accepted.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('explicitly configured primary collector', $error->getMessage());
                }
            }
            $GLOBALS['config'] = [];
            $this->expectException(\RuntimeException::class);
            \cdef_reference_primary_connection();
        } finally {
            foreach ($names as $name) {
                unset($GLOBALS[$name]);
            }
            foreach ($saved as $name => $value) {
                $GLOBALS[$name] = $value;
            }
        }
    }

    public function testUnavailableSelectedConnectionNeverFallsBackToAnotherSession(): void
    {
        $names = ['config', 'database_sessions', 'database_hostname', 'database_port', 'database_default'];
        $saved = array_intersect_key($GLOBALS, array_flip($names));
        try {
            $GLOBALS['config'] = ['poller_id' => 1];
            $GLOBALS['database_hostname'] = 'fixture';
            $GLOBALS['database_port'] = 1;
            $GLOBALS['database_default'] = 'missing';
            $GLOBALS['database_sessions'] = ['fixture:1:other' => new \PDO('sqlite::memory:')];
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('selected primary CDEF database connection is unavailable');
            \cdef_reference_primary_connection();
        } finally {
            foreach ($names as $name) {
                unset($GLOBALS[$name]);
            }
            foreach ($saved as $name => $value) {
                $GLOBALS[$name] = $value;
            }
        }
    }
}
