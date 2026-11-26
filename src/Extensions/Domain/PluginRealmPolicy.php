<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Extensions\Domain;

/**
 * Protects core authorization realms (0-99) and external-link realms (10000+)
 * from plugin registration and denies realm activation for inactive plugins.
 */
final readonly class PluginRealmPolicy
{
    public const int MIN_PLUGIN_REALM_ID = 100;
    public const int MAX_PLUGIN_REALM_ID = 9999;

    public function isCoreRealm(int $realmId): bool
    {
        return $realmId >= 0 && $realmId < self::MIN_PLUGIN_REALM_ID;
    }

    public function isPluginRealmRange(int $realmId): bool
    {
        return $realmId >= self::MIN_PLUGIN_REALM_ID && $realmId <= self::MAX_PLUGIN_REALM_ID;
    }

    public function canRegisterRealm(int $realmId, PluginStatus $status): bool
    {
        return $status->isActive() && $this->isPluginRealmRange($realmId);
    }

    public function assertRealmRegistrable(int $realmId, PluginStatus $status): void
    {
        if ($this->isCoreRealm($realmId)) {
            throw new \DomainException(sprintf('Plugin cannot overwrite core realm %d.', $realmId));
        }

        if (!$this->isPluginRealmRange($realmId)) {
            throw new \DomainException(sprintf('Realm %d is outside the plugin realm range (%d-%d).', $realmId, self::MIN_PLUGIN_REALM_ID, self::MAX_PLUGIN_REALM_ID));
        }

        if (!$status->isActive()) {
            throw new \DomainException(sprintf('Inactive plugin in status "%s" cannot register realm %d.', $status->value, $realmId));
        }
    }
}
