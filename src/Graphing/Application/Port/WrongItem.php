<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** A graph item that follows the data template but reads another RRD item; $taskItemId is the item it reads now. */
final readonly class WrongItem
{
    public function __construct(public int $id, public int $taskItemId) {}
}
