<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\ModelRegistry;

use Kadupul\ModelRegistry\Domain\Checksum;
use Kadupul\ModelRegistry\Domain\Model;
use Kadupul\ModelRegistry\Domain\ModelDependency;
use Kadupul\ModelRegistry\Domain\ModelIdentity;
use Kadupul\ModelRegistry\Domain\ModelRevision;
use Kadupul\ModelRegistry\Domain\Provenance;

final class ModelFixtures
{
    public static function identity(string $name, string $family = 'yang'): ModelIdentity
    {
        return ModelIdentity::parse($family, 'urn:example:' . $name, $name);
    }

    public static function revision(string $value, string $family = 'yang'): ModelRevision
    {
        return ModelRevision::parse(\Kadupul\ModelRegistry\Domain\ModelFamily::fromString($family), $value);
    }

    public static function checksum(string $seed = 'a'): Checksum
    {
        return Checksum::fromHex(hash('sha256', $seed));
    }

    public static function provenance(string $source = 'vendor-repo'): Provenance
    {
        return new Provenance($source, new \DateTimeImmutable('2026-05-01T10:00:00+00:00'), null);
    }

    /**
     * @param list<ModelDependency> $dependencies
     * @param list<string> $capabilities
     */
    public static function model(string $name, string $revision = '2024-01-15', array $dependencies = [], string $seed = 'a', array $capabilities = ['interfaces']): Model
    {
        return new Model(
            self::identity($name),
            self::revision($revision),
            $dependencies,
            $capabilities,
            self::provenance(),
            self::checksum($seed . $name . $revision),
        );
    }

    public static function dependsOn(string $name, ?string $minimum = null): ModelDependency
    {
        return new ModelDependency(self::identity($name), $minimum === null ? null : self::revision($minimum));
    }
}
