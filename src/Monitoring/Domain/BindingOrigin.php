<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** How a binding came to exist: model-driven planning, legacy discovery or an operator. */
enum BindingOrigin: string
{
    case ModelDriven = 'model_driven';
    case LegacyDiscovered = 'legacy_discovered';
    case Operator = 'operator';
}
