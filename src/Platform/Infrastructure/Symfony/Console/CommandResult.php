<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Symfony\Component\Console\Command\Command;

/** What ResultRenderer prints for --json and for a cli/ shim; human output never passes through here. */
final readonly class CommandResult
{
    /**
     * @param array<string, mixed> $json stable machine-readable keys
     * @param list<string> $legacy the original script's stdout lines
     */
    public function __construct(public array $json, public array $legacy, public int $exit = Command::SUCCESS) {}
}
