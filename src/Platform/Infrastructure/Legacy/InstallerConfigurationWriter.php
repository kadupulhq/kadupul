<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Persist installation choices locally without changing collector routing. */
final readonly class InstallerConfigurationWriter
{
    /** Bind the legacy settings/cache hooks at the infrastructure boundary. */
    public function __construct(private NativeInstallerFunctions $native) {}

    /** The selected local connection is opaque to the legacy compatibility caller. */
    public function write(string $name, mixed $value, string $projectDir, ?object $localConnection): void
    {
        if (in_array($name, ['path_cactilog', 'path_stderrlog'], true)) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('An installation log path must be text.');
            }
            if (pathinfo($value, PATHINFO_EXTENSION) !== 'log') {
                $value = $projectDir . '/log/' . ($name === 'path_cactilog' ? 'cacti.log' : 'cacti.stderr.log');
            }
        }
        if ($localConnection !== null) {
            $result = $this->native->call('db_execute_prepared', 'REPLACE INTO settings (name, value) VALUES (?, ?)', [$name, $value], false, $localConnection);
        } else {
            $result = $this->native->call('set_config_option', $name, $value);
        }
        if ($result === false) {
            throw new \RuntimeException('The installation setting could not be stored.');
        }
    }
}
