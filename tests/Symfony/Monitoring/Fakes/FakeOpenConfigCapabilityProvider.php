<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring\Fakes;

/** OpenConfig models as a gNMI CapabilityResponse would list them. */
final class FakeOpenConfigCapabilityProvider extends FakeCapabilityProvider
{
    public function protocol(): string
    {
        return 'gnmi';
    }

    protected function family(): string
    {
        return 'openconfig';
    }
}
