<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

/**
 * A stored alerting row that the domain cannot have written, such as a
 * counter or flag changed by hand. It is reported, not repaired, so a wrong
 * row never decides whether an incident opens or closes.
 */
final class StoredAlertingRowUnreadable extends \UnexpectedValueException
{
    public static function because(string $row, string $reason, ?\Throwable $previous = null): self
    {
        return new self('The ' . $row . ' row cannot be read: ' . $reason, 0, $previous);
    }
}
