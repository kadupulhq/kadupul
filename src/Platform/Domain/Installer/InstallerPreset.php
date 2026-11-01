<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Installer;

/** Defaults for existing native deployment roles, never license or execution consent. */
final class InstallerPreset
{
    /** @param array<array-key, mixed> $options
     *  @return array<array-key, mixed>
     */
    public static function apply(string $name, #[\SensitiveParameter] array $options): array
    {
        $mode = match ($name) {
            'standalone' => '1',
            'collector' => '2',
            default => throw new \InvalidArgumentException('Unknown installer preset. Choose standalone or collector.'),
        };
        if (array_key_exists('Mode', $options) && $options['Mode'] !== $mode && $options['Mode'] !== (int) $mode) {
            throw new \InvalidArgumentException('The requested mode conflicts with the installer preset.');
        }
        // The backend checks this expected mode against the actual deployment
        // before commit. No profile IDs, credentials or template names are guessed.
        return array_replace(['Mode' => $mode, 'AutomationMode' => '0'], $options);
    }
}
