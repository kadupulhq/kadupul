<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/** Initialize the configured external secret without replacing an existing initializer's value. */
final readonly class InstallerCsrfSecret
{
    /** The native boundary owns secret generation and document-root containment policy. */
    public function __construct(private NativeInstallerFunctions $native, private Filesystem $files = new Filesystem()) {}

    /** Publish complete bytes through an exclusive hard-link operation in the destination directory. */
    public function create(string $path): bool
    {
        if ($this->native->call('cacti_csrf_external_path_is_safe', $path) !== true || is_link($path)) {
            return false;
        }
        if (file_exists($path)) {
            return $this->existing($path);
        }
        $directory = realpath(dirname($path));
        if ($directory === false || !is_writable($directory)) {
            return false;
        }
        $temporary = null;
        try {
            $temporary = $this->files->tempnam($directory, '.installer-secret-');
            $secret = $this->native->call('csrf_get_secret');
            if (dirname($temporary) !== $directory || !is_string($secret) || $secret === '') {
                return false;
            }
            $this->files->chmod($temporary, 0600);
            $this->files->dumpFile($temporary, $secret);
            if (!@link($temporary, $path)) {
                return $this->existing($path);
            }
            return hash_file('sha256', $path) === hash('sha256', $secret);
        } catch (IOExceptionInterface) {
            return false;
        } finally {
            if ($temporary !== null) {
                $this->files->remove($temporary);
            }
        }
    }

    /** Existing files are observed without truncation, permissions changes or secret disclosure. */
    private function existing(string $path): bool
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            return false;
        }
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        return is_string($bytes) && $bytes !== '';
    }
}
