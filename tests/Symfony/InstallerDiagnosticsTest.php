<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Application\Installer\InstallerDiagnostics;
use PHPUnit\Framework\TestCase;

final class InstallerDiagnosticsTest extends TestCase
{
    public function testUnexpectedFieldsAndMalformedValuesCannotReachTheExport(): void
    {
        $data = InstallerDiagnostics::export([
            'mode' => 'private-host', 'schema' => 'private-credential', 'target' => ['1.3.0'],
            'job' => ['state' => 'private-token', 'progress' => -1, 'attempt' => '1', 'pending_collectors' => [],
                'phase' => 'private-path', 'error' => 'private-password',
                'health' => [['name' => 'private-name', 'status' => 'private-status'], ['status' => 'not_assessed']]],
            'checks' => [['ok' => 'false'], ['ok' => true, 'name' => 'private-key']],
            'advisories' => [['status' => 'not_assessed', 'observed' => 'private-host']],
        ]);
        $this->assertNull($data['operation']);
        $this->assertNull($data['schema']);
        $this->assertNull($data['target']);
        foreach (['state', 'progress', 'phase', 'attempt', 'pending_collectors'] as $field) {
            $this->assertNull($data['execution'][$field]);
        }
        $this->assertSame([['number' => 2, 'passed' => true]], $data['requirements']);
        $this->assertSame([['number' => 1, 'status' => null], ['number' => 2, 'status' => 'not_assessed']], $data['health']);
        $this->assertSame([['number' => 1, 'status' => 'not_assessed']], $data['advisories']);
        $this->assertStringNotContainsString('private-', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
