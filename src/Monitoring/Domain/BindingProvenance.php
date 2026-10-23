<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

final readonly class BindingProvenance
{
    public \DateTimeImmutable $recordedAt;

    /** @param string $sourceDetail what produced the binding, such as the legacy data source or the operator */
    public function __construct(
        public BindingOrigin $origin,
        public string $sourceDetail,
        \DateTimeImmutable $recordedAt,
    ) {
        if (trim($sourceDetail) === '') {
            throw new InvalidBinding('Binding provenance needs a source detail so the binding can be traced back.');
        }
        $this->recordedAt = $recordedAt->setTimezone(new \DateTimeZone('UTC'));
    }
}
