<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Contract;

/**
 * Who writes a storage field, as other modules see it. It mirrors the
 * Monitoring domain enum case for case; the domain cannot depend on its own
 * Contract, so the two are kept equal by a test rather than shared.
 */
enum WriterOwnership: string
{
    case LegacyPoller = 'legacy_poller';
    case ModelDriven = 'model_driven';
    case Unassigned = 'unassigned';
    case Conflict = 'conflict';
}
