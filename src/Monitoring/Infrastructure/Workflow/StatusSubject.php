<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Monitoring\Infrastructure\Workflow;

/** A workflow subject whose whole marking is one place named by a domain enum value. */
interface StatusSubject
{
    /** The current place name. */
    public function place(): string;

    /**
     * @throws \ValueError when the name is not a valid place of the underlying enum
     */
    public function moveTo(string $place): void;
}
