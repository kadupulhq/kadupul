<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** A custom input a graph template's data templates ask for, as --list-input-fields prints it. */
final readonly class InputField
{
    public function __construct(
        public string $name,
        public string $description,
        public string $default,
        public int $dataTemplateId,
        public int $dataInputFieldId,
    ) {}

    /** The key the original filed the field under, also how an operator names it. */
    public function key(): string
    {
        return $this->dataTemplateId . ':' . $this->name;
    }
}
