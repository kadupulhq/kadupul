<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Persistence;

use Kadupul\Monitoring\Domain\StorageField;

/** Claims for one process; lost when it ends. */
final class InMemoryWriterClaimStore implements WriterClaimStore
{
    /** @var array<string, StorageField> */
    private array $claims = [];

    public function holds(StorageField $field): bool
    {
        return isset($this->claims[$field->toString()]);
    }

    public function record(StorageField $field): bool
    {
        if ($this->holds($field)) {
            return false;
        }
        $this->claims[$field->toString()] = $field;

        return true;
    }

    public function all(): array
    {
        return array_values($this->claims);
    }
}
