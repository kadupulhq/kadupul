<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** A device as --list-hosts prints it. */
final readonly class RemovalDevice
{
    public function __construct(public int $id, public string $hostname, public int $hostTemplateId, public string $description) {}
}
