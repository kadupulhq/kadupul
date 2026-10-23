<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/** Why a sink did not store a sample. The codes are stable; callers branch on them, not on the detail text. */
enum RejectionReason
{
    case NoBinding;
    case AmbiguousBinding;
    case WritesDisabled;
    case OwnershipConflict;
    case NotOwnedByModelDriven;
    case TargetMissing;
    case TypeMismatch;
    case InvalidTimestamp;
    case InvalidValue;
    case DuplicateSample;
    case OutOfOrder;
    case StorageFailure;
}
