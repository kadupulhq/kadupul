<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

interface InstallerWebContext
{
    /** @return array{action: string, root: string, csrf: string, locale: string} */
    public function view(): array;

    public function validPost(): bool;
}
