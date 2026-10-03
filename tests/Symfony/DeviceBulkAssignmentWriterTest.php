<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DeviceBulkAssignmentWriterTest extends TestCase
{
    private function observe(string $mode): array
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/bulk-assignment-writer-native.php', $mode]);
        $process->mustRun();
        self::assertSame('', $process->getErrorOutput());
        return json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
    }

    public function testExistingTemplateRepairsMissingAssociationAndInvokesHostHook(): void
    {
        $result = $this->observe('template-existing');
        self::assertNull($result['error']);
        self::assertSame([['host_id' => 7, 'graph_template_id' => 5]], $result['graphs']);
        self::assertSame([['template_api', [7, 9]], ['host_save', ['host_id' => 7]]], $result['events']);
        self::assertTrue($result['active']);
    }

    public function testRemotePreflightDisabledStateIsPreservedAndRevalidated(): void
    {
        $result = $this->observe('site-drift');
        self::assertNull($result['error']);
        self::assertSame(5, $result['primary'][0]['site_id']);
        self::assertSame(5, $result['remote'][0]['site_id']);
        self::assertSame('on', $result['remote'][0]['disabled']);
        foreach (['site-drift-changed', 'site-wrong-owner', 'site-template-mismatch', 'site-primary-owner-mismatch', 'site-primary-site-mismatch'] as $mode) {
            self::assertSame('Assignment could not be confirmed', $this->observe($mode)['error']);
        }
    }

    public function testUnassignmentHooksAndNoopsMatchTheLegacyContract(): void
    {
        $result = $this->observe('template-remove');
        self::assertNull($result['error']);
        self::assertSame([['device_template_change', ['device_id' => 7, 'device_template_id' => 0]], ['host_save', ['host_id' => 7]]], $result['events']);
        self::assertSame(0, $result['primary'][0]['host_template_id']);
        self::assertSame(0, $result['remote'][0]['host_template_id']);
        foreach (['site-noop', 'collector-noop'] as $mode) {
            $result = $this->observe($mode);
            self::assertNull($result['error']);
            self::assertSame([], $result['events']);
            self::assertTrue($result['active']);
        }
    }

    public function testUnchangedCollectorRejectsMismatchedPollingOwnership(): void
    {
        self::assertSame('Polling ownership mismatch', $this->observe('collector-wrong-polling-owner')['error']);
    }

    public function testMissingUpdateDoesNotInvokeHooksOrEndCallerTransaction(): void
    {
        $result = $this->observe('site-missing');
        self::assertSame('Assignment failed', $result['error']);
        self::assertSame([], $result['events']);
        self::assertTrue($result['active']);
    }
}
