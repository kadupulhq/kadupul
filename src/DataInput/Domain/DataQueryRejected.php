<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\DataInput\Domain;

/** A data query edit the form must refuse, with the field it concerns when there is one. */
final class DataQueryRejected extends \InvalidArgumentException
{
    /** @param array<string, string> $parameters translation parameters for the message */
    public function __construct(string $message, public readonly array $parameters = [], public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
