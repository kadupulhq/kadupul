<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Port;

/**
 * Looks up the locations used by devices.
 */
interface DeviceLocations
{
    /**
     * Returns the distinct, non-empty locations of the devices visible to the actor that contain the term.
     *
     * @return array List of string.
     */
    public function matching(int $actorId, string $term): array;
}
