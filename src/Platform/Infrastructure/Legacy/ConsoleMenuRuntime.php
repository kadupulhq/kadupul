<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/**
 * Called only by the isolated, authenticated browser worker, after the legacy
 * bootstrap has run every config_arrays hook. The worker passes its $menu and
 * $user_auth_realm_filenames in, so no procedural state is read from here.
 */
final class ConsoleMenuRuntime
{
    /**
     * @param array<mixed> $menu
     * @param array<mixed> $realms script basename => realm id
     * @param list<string> $sections
     * @param \Closure(string): string $translate the legacy __()
     * @return array{entries: list<array{section: string, label: string, path: string, realm: ?int}>}
     */
    public function capture(array $menu, array $realms, array $sections, \Closure $translate): array
    {
        $names = [];
        foreach ($sections as $section) {
            $names[$translate($section)] ??= $section;
        }
        $entries = [];
        foreach ($menu as $heading => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $path => $label) {
                // draw_menu() checks the parent's realm and always shows the
                // first child of a submenu, so that child stands for the item.
                $realm = $realms[basename((string) strtok((string) $path, '?'))] ?? null;
                if (is_array($label)) {
                    $path = array_key_first($label);
                    $label = $path === null ? null : $label[$path];
                }
                if (!is_string($label) || $path === null) {
                    continue;
                }
                $realm = filter_var($realm, FILTER_VALIDATE_INT);
                $entries[] = [
                    'section' => $names[(string) $heading] ?? (string) $heading,
                    'label' => $label,
                    'path' => (string) $path,
                    'realm' => $realm === false ? null : $realm,
                ];
            }
        }

        return ['entries' => $entries];
    }
}
