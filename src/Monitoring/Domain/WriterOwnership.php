<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * Who writes a storage field. A field written by the legacy poller and also
 * claimed by model-driven collection is a conflict, and nothing writes to it
 * until an operator settles it.
 */
enum WriterOwnership: string
{
    case LegacyPoller = 'legacy_poller';
    case ModelDriven = 'model_driven';
    case Unassigned = 'unassigned';
    case Conflict = 'conflict';
}
