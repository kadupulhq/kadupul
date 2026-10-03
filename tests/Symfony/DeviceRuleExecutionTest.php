<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Legacy {
    function automation_update_device(int $id): mixed
    {
        \Kadupul\Tests\DeviceRuleExecutionTest::$calls[] = $id;
        return $id === 9 ? false : true;
    }
}

namespace Kadupul\Tests {
    use Kadupul\Automation\Infrastructure\Legacy\LegacyDeviceRules;
    use PHPUnit\Framework\TestCase;

    final class DeviceRuleExecutionTest extends TestCase
    {
        public static array $calls = [];
        public function testExistingRulesExecuteOncePerSelectedDevice(): void
        {
            self::$calls = [];
            (new LegacyDeviceRules())->apply([7,8]);
            self::assertSame([7,8], self::$calls);
        }
        public function testInvalidSelectionIsRejectedBeforeAnyRules(): void
        {
            self::$calls = [];
            try {
                (new LegacyDeviceRules())->apply([7,'8']);
                self::fail('Invalid selection accepted');
            } catch (\InvalidArgumentException) {
                self::assertSame([], self::$calls);
            }
        }
        public function testFailedRuleResultCannotReportSuccess(): void
        {
            $this->expectException(\RuntimeException::class);
            (new LegacyDeviceRules())->apply([9]);
        }
    }
}
