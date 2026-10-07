<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Legacy;

use Kadupul\Automation\Contract\DeviceRules;

final class LegacyDeviceRules implements DeviceRules
{
    public function apply(array $deviceIds): void
    {
        foreach ($deviceIds as $id) {
            if (!is_int($id) || $id < 1 || $id > 16777215) {
                throw new \InvalidArgumentException('Invalid automation device');
            }
        }
        foreach ($deviceIds as $id) {
            if (automation_update_device($id) !== true) {
                throw new \RuntimeException('Device automation failed');
            }
        }
    }
}
