<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Contract;

/**
 * Public cross-context contract for dispatching typed extension hooks.
 */
interface HookDispatcher
{
    public function dispatch(HookEvent $event): HookEvent;
}
