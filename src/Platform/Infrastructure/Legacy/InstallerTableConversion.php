<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Application\Command\TableConversionStep;
use Kadupul\Platform\Application\Port\DatabaseTarget;
use Kadupul\Platform\Application\Port\TableConversion;
use Kadupul\Platform\Domain\Schema\ConversionFlag;
use Kadupul\Platform\Domain\Schema\ConversionOptions;

/**
 * The install wizard's conversion of the tables it queued. The installer is
 * the authority during an install, so this checks no operator and records no
 * audit event. It is reachable only from lib/installer.php: no command and no
 * route wraps it, and shell callers go through ConvertTables, which checks
 * the operator.
 */
final readonly class InstallerTableConversion
{
    public function __construct(private TableConversion $conversion, private TableConversionStep $step) {}

    /** lib/installer.php has no container, so this boots one as LegacyCli does. */
    public static function run(string $table): InstallerTableResult
    {
        try {
            // src/Platform/Infrastructure/Legacy -> repository root is 4 levels up.
            $kernel = require dirname(__DIR__, 4) . '/config/bootstrap.php';
            try {
                $kernel->boot();

                return $kernel->getContainer()->get(self::class)->convert($table);
            } finally {
                $kernel->shutdown();
            }
        } catch (\Throwable $error) {
            // The table stays queued, as it did when the script failed.
            return InstallerTableResult::failed($error);
        }
    }

    public function convert(string $table): InstallerTableResult
    {
        // The flags the installer passed: --table=<name> --utf8 --innodb --dynamic.
        $options = new ConversionOptions([ConversionFlag::Utf8, ConversionFlag::Innodb, ConversionFlag::Dynamic], $table, [], '1000000');
        $catalog = $this->conversion->tableStatuses(DatabaseTarget::Local);
        $blocker = $this->step->blocker(DatabaseTarget::Local, $options);
        if ($blocker !== null) {
            return InstallerTableResult::stopped($blocker, $options);
        }

        return InstallerTableResult::table(($this->step)(DatabaseTarget::Local, $table, $catalog, $options, true), $options);
    }
}
