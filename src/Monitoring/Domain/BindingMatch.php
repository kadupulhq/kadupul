<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * How well a legacy data-source field matches a logical series. Only a
 * confirmed match may become a binding; ambiguous and heuristic matches are
 * reported for an operator and never bound automatically.
 */
final readonly class BindingMatch
{
    /** @param list<string> $reasons */
    public function __construct(
        public MatchConfidence $confidence,
        public array $reasons,
        public ?StorageField $field,
        public ?RrdDataSourceType $type,
    ) {
        if ($reasons === []) {
            throw new InvalidBinding('A binding match must give at least one reason.');
        }
        if ($confidence === MatchConfidence::Confirmed && ($field === null || $type === null)) {
            throw new InvalidBinding('A confirmed match names the storage field and its data-source type.');
        }
    }

    public function canBind(): bool
    {
        return $this->confidence === MatchConfidence::Confirmed;
    }
}
