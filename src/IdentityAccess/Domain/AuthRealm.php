<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Domain;

/**
 * Core permission realms stored in user_auth_realm and user_auth_group_realm.
 * Matches $user_auth_realms in include/global_arrays.php.
 */
enum AuthRealm: int
{
    case UsersGroups = 1;
    case DataInputMethods = 2;
    case SitesDevicesData = 3;
    case Trees = 4;
    case Graphs = 5;
    case ViewGraphs = 7;
    case ConsoleAccess = 8;
    case DataSourceProfiles = 9;
    case GraphTemplates = 10;
    case DataTemplates = 11;
    case DeviceTemplates = 12;
    case DataQueries = 13;
    case Presets = 14;
    case SettingsUtilities = 15;
    case ExportTemplates = 16;
    case ImportTemplates = 17;
    case LogAdministration = 18;
    case LogViewing = 19;
    case UpdateProfile = 20;
    case ReportsAdministration = 21;
    case ReportsCreation = 22;
    case Automation = 23;
    case ExternalLinks = 24;
    case RealtimeGraphs = 25;
    case InstallationUpgrades = 26;
    case GraphActionIcons = 27;
    case UserHelpLinks = 28;
    case AlertRules = 29;
    case AlertIncidents = 30;
    case PluginAdministration = 101;
    case SpikeHandling = 1043;

    public function label(): string
    {
        return match ($this) {
            self::UsersGroups => 'Users/Groups',
            self::DataInputMethods => 'Data Input Methods',
            self::SitesDevicesData => 'Sites/Devices/Data',
            self::Trees => 'Trees',
            self::Graphs => 'Graphs',
            self::ViewGraphs => 'View Graphs',
            self::ConsoleAccess => 'Console Access',
            self::DataSourceProfiles => 'Data Source Profiles',
            self::GraphTemplates => 'Graph Templates',
            self::DataTemplates => 'Data Templates',
            self::DeviceTemplates => 'Device Templates',
            self::DataQueries => 'Data Queries',
            self::Presets => 'Presets',
            self::SettingsUtilities => 'Settings/Utilities',
            self::ExportTemplates => 'Export Templates',
            self::ImportTemplates => 'Import Templates',
            self::LogAdministration => 'Log Administration',
            self::LogViewing => 'Log Viewing',
            self::UpdateProfile => 'Update Profile',
            self::ReportsAdministration => 'Reports Administration',
            self::ReportsCreation => 'Reports Creation',
            self::Automation => 'Automation',
            self::ExternalLinks => 'External Links',
            self::RealtimeGraphs => 'Realtime Graphs',
            self::InstallationUpgrades => 'Installation/Upgrades',
            self::GraphActionIcons => 'Show Graph Action Icons',
            self::UserHelpLinks => 'Show User Help Links',
            self::AlertRules => 'Alert Rules',
            self::AlertIncidents => 'Alert Incidents',
            self::PluginAdministration => 'Plugin Administration',
            self::SpikeHandling => 'Spike Handling',
        };
    }

    /** True for administrative realms that require ConsoleAccess (realm 8). */
    public function requiresConsoleAccess(): bool
    {
        return !in_array($this, [
            self::ViewGraphs,
            self::LogViewing,
            self::UpdateProfile,
            self::ReportsCreation,
            self::ExternalLinks,
            self::RealtimeGraphs,
            self::GraphActionIcons,
            self::UserHelpLinks,
        ], true);
    }

    public function securityAttribute(): string
    {
        return 'KADUPUL_REALM_' . $this->value;
    }

    public static function fromSecurityAttribute(string $attribute): ?self
    {
        if (!str_starts_with($attribute, 'KADUPUL_REALM_')) {
            return null;
        }
        $suffix = substr($attribute, 14);
        if (!ctype_digit($suffix)) {
            return null;
        }

        return self::tryFrom((int) $suffix);
    }
}
