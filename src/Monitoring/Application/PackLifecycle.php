<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Application;

use Kadupul\Monitoring\Application\Port\PackRepository;
use Kadupul\Monitoring\Domain\InstalledPack;
use Kadupul\Monitoring\Domain\PackLifecycleViolation;
use Kadupul\Monitoring\Domain\PackState;
use Kadupul\Monitoring\Domain\PackUpgrade;
use Kadupul\Monitoring\Domain\PackValidator;

/**
 * Install, upgrade and deprecate packs. Every document is validated before
 * anything is stored, and a refused operation leaves the repository as it was.
 */
final class PackLifecycle
{
    /** Wires the pack repository and the validator. */
    public function __construct(private readonly PackRepository $packs, private readonly PackValidator $validator) {}

    /**
     * @param mixed $document a pack decoded with objects as \stdClass
     * @param string $source where the document came from, named in every violation
     */
    public function install(mixed $document, string $source): InstalledPack
    {
        $pack = $this->validator->parse($document, $source);
        $existing = $this->packs->find($pack->name);
        if ($existing !== null) {
            throw new PackLifecycleViolation(sprintf('Pack %s is already installed at %s; upgrade it instead.', $pack->name, $existing->pack->version->toString()));
        }
        $installed = new InstalledPack($pack, PackState::Installed);
        $this->packs->save($installed);

        return $installed;
    }

    /**
     * Replaces an installed pack with a newer validated version of it.
     *
     * @throws \Kadupul\Monitoring\Domain\InvalidPack when the document is not a valid pack
     * @throws PackLifecycleViolation when the pack is not installed, the version is not newer, or the change is breaking without a new major version
     */
    public function upgrade(mixed $document, string $source): PackUpgrade
    {
        $pack = $this->validator->parse($document, $source);
        $existing = $this->packs->find($pack->name)
            ?? throw new PackLifecycleViolation(sprintf('Pack %s is not installed; install it before upgrading.', $pack->name));
        $upgrade = $existing->upgradeTo($pack);
        $this->packs->save($upgrade->pack);

        return $upgrade;
    }

    /**
     * Marks an installed pack as deprecated.
     *
     * @throws PackLifecycleViolation when no pack has that name
     */
    public function deprecate(string $name): InstalledPack
    {
        $existing = $this->packs->find($name)
            ?? throw new PackLifecycleViolation(sprintf('Pack %s is not installed.', $name));
        $deprecated = $existing->withState(PackState::Deprecated);
        $this->packs->save($deprecated);

        return $deprecated;
    }
}
