<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

final class UnsupportedModelRevision extends \DomainException
{
    /**
     * @param list<ModelRevision> $registered
     */
    private function __construct(string $message, private readonly array $registered)
    {
        parent::__construct($message);
    }

    /**
     * @param list<ModelRevision> $registered
     */
    public static function notRegistered(ModelIdentity $identity, ModelRevision $requested, array $registered): self
    {
        $known = $registered === []
            ? 'no revision is registered'
            : 'registered revisions: ' . implode(', ', array_map(static fn(ModelRevision $r): string => $r->value(), $registered));

        return new self(sprintf('Model %s has no revision %s; %s.', $identity->key(), $requested->value(), $known), $registered);
    }

    /**
     * @param list<ModelRevision> $registered
     */
    public static function belowMinimum(ModelIdentity $identity, ModelRevision $minimum, array $registered, string $requiredBy): self
    {
        return new self(sprintf(
            'Model %s requires %s at revision %s or newer; registered revisions: %s.',
            $requiredBy,
            $identity->key(),
            $minimum->value(),
            implode(', ', array_map(static fn(ModelRevision $r): string => $r->value(), $registered)),
        ), $registered);
    }

    /**
     * @return list<ModelRevision>
     */
    public function registered(): array
    {
        return $this->registered;
    }
}
