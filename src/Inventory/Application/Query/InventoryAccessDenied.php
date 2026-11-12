<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Query;

/**
 * Thrown when an inventory operation is refused.
 * The flag tells a caller whether to ask the user to sign in (true) or to report a missing permission (false).
 */
final class InventoryAccessDenied extends \RuntimeException
{
    /**
     * Sets the message from the reason for the refusal.
     *
     * @param bool $unauthenticated True when nobody is signed in; false when the user lacks device administration permission or may not see the target.
     */
    public function __construct(public readonly bool $unauthenticated)
    {
        parent::__construct($unauthenticated ? 'Authentication required.' : 'Device administration permission required.');
    }
}
