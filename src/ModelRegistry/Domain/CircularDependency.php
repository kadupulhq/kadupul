<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

final class CircularDependency extends \DomainException
{
    /**
     * @param list<string> $path identity names from the start of the cycle back to its repeat
     */
    public function __construct(array $path)
    {
        parent::__construct('Circular model dependency: ' . implode(' -> ', $path) . '.');
    }
}
