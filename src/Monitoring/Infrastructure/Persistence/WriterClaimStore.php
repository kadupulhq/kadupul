<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Persistence;

use Kadupul\Monitoring\Domain\StorageField;

/**
 * Where WriterOwnershipRegistry keeps model-driven claims. It holds no rules;
 * the registry decides whether a claim may be made. A field is claimed at
 * most once, so two writers racing for it end with one claim.
 */
interface WriterClaimStore
{
    public function holds(StorageField $field): bool;

    /** False when the field was already claimed, whether by this caller or a concurrent one. */
    public function record(StorageField $field): bool;

    /** @return list<StorageField> */
    public function all(): array;
}
