<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Contract;

interface DeviceRules
{
    /** Apply existing configured rules to authorized, locked devices. */
    public function apply(array $deviceIds): void;
}
