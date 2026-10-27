<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Diagnostics;

enum DiagnosticScreen: string
{
    case Menu = 'menu';
    case Summary = 'summary';
    case Database = 'database';
    case DatabaseSettings = 'database-settings';
    case DatabaseStatus = 'database-status';
    case Php = 'php';
    case Changelog = 'changelog';
    case Boost = 'boost';
    case PollerCache = 'poller-cache';
    case DataQueryCache = 'data-query-cache';

    public function title(): string
    {
        return match ($this) {
            self::Menu => 'System Utilities', self::Summary => 'Technical Support',
            self::Database => 'Database Tables', self::DatabaseSettings => 'Database Settings',
            self::DatabaseStatus => 'Database Status', self::Php => 'PHP Information',
            self::Changelog => 'ChangeLog', self::Boost => 'Boost Status',
            self::PollerCache => 'Poller Cache', self::DataQueryCache => 'Data Query Cache',
        };
    }

    public function legacyQuery(): string
    {
        return match ($this) {
            self::Menu => '', self::Boost => '?action=view_boost_status',
            self::PollerCache => '?action=view_poller_cache',
            self::DataQueryCache => '?action=view_snmp_cache',
            default => '?action=view_tech&tab=' . match ($this) {
                self::DatabaseSettings => 'dbsettings', self::DatabaseStatus => 'dbstatus',
                self::Php => 'phpinfo', default => $this->value,
            },
        };
    }
}
