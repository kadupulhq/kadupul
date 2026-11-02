<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Read-only prerequisites; deliberately never launches a process or writes a probe file. */
final class InstallerRuntimeChecks
{
    /** @return list<array{name: string, ok: bool, observed: string, remedy: string}> */
    public static function inspect(string $projectDir, bool $background): array
    {
        $checks = [];
        foreach ([$background ? 'install/background.php' : 'bin/legacy-installer.php', 'include/vendor/autoload.php'] as $relative) {
            $readable = is_file($projectDir . '/' . $relative) && is_readable($projectDir . '/' . $relative);
            $checks[] = ['name' => 'Worker file: ' . $relative, 'ok' => $readable,
                'observed' => $readable ? 'readable' : 'missing or unreadable',
                'remedy' => 'Restore the complete release and its locked dependencies; grant the service account read access.'];
        }
        $temporary = sys_get_temp_dir();
        $writable = is_dir($temporary) && is_writable($temporary);
        $checks[] = ['name' => 'PHP temporary directory', 'ok' => $writable,
            'observed' => $writable ? 'directory writable by this process' : 'missing or not writable',
            'remedy' => 'Configure sys_temp_dir as an existing directory writable by the installation account. Native imports use temporary files.'];
        if ($background) {
            // This is the actual exec_background() platform branch. CLI work
            // executes synchronously and does not require this launch facility.
            $functions = PHP_OS_FAMILY === 'Windows' ? ['popen', 'pclose'] : ['exec'];
            $available = !in_array(false, array_map(function_exists(...), $functions), true);
            $checks[] = ['name' => 'Background worker launch', 'ok' => $available,
                'observed' => $available ? 'required PHP functions available; launch not exercised' : 'required PHP launch function disabled',
                'remedy' => 'Enable ' . implode(', ', $functions) . ' for the web PHP runtime, or use the authorized CLI installer.'];
        }
        return $checks;
    }
}
