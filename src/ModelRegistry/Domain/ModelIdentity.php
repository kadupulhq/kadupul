<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

final class ModelIdentity
{
    // The key joins parts with "|", so neither part may contain it.
    private const NAMESPACE_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:\/#+-]{0,254}\z/';
    private const NAME_PATTERN = '/\A[A-Za-z][A-Za-z0-9._-]{0,127}\z/';

    public function __construct(public readonly ModelFamily $family, public readonly string $namespace, public readonly string $name)
    {
        if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
            throw new InvalidModelDefinition(sprintf('Invalid model namespace "%s".', $namespace));
        }
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidModelDefinition(sprintf('Invalid model name "%s".', $name));
        }
    }

    public static function parse(string $family, string $namespace, string $name): self
    {
        return new self(ModelFamily::fromString($family), $namespace, $name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function key(): string
    {
        return $this->family->value . '|' . $this->namespace . '|' . $this->name;
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }
}
