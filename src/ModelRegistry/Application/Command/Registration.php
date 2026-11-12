<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Application\Command;

/**
 * Outcome of registering a model revision.
 */
enum Registration
{
    case Created;
    case AlreadyRegistered;
}
