<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

enum PackState: string
{
    case Installed = 'installed';
    case Active = 'active';
    case Deprecated = 'deprecated';
}
