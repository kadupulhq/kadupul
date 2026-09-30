<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/**
 * Semantic icon names and the Font Awesome classes that draw them.
 *
 * config/icons.json is the only copy of the map. lib/html.php renders from it
 * and html_common_header() hands the same resolved map to layout.js, so PHP,
 * core JavaScript and the themes cannot drift apart. A theme may redraw a
 * name but not add one, which keeps every call site valid in every theme.
 */
final readonly class IconRegistry
{
    private const NAME = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const THEME = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/';
    // Class tokens only, so a value never needs escaping to sit in an attribute.
    private const CLASSES = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?: [a-z0-9]+(?:-[a-z0-9]+)*)*$/';

    /**
     * @param array<string, string> $icons name => classes
     * @param array<string, array<string, string>> $themes theme => (name => classes)
     */
    public function __construct(private array $icons, private array $themes = [])
    {
        if ($icons === []) {
            throw new \InvalidArgumentException('The icon registry is empty');
        }
        self::checkEntries($icons, 'icons');
        foreach ($themes as $theme => $overrides) {
            if (!is_string($theme) || preg_match(self::THEME, $theme) !== 1) {
                throw new \InvalidArgumentException('Invalid theme name in the icon registry: ' . $theme);
            }
            if (!is_array($overrides)) {
                throw new \InvalidArgumentException("Theme $theme must map icon names to classes");
            }
            self::checkEntries($overrides, "theme $theme");
            $unknown = array_diff_key($overrides, $icons);
            if ($unknown !== []) {
                throw new \InvalidArgumentException("Theme $theme overrides unknown icons: " . implode(', ', array_keys($unknown)));
            }
        }
    }

    /** Reads the {"icons": {...}, "themes": {...}} document config/icons.json holds. */
    public static function fromJson(string $json): self
    {
        $document = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($document) || !is_array($document['icons'] ?? null) || !is_array($document['themes'] ?? [])) {
            throw new \InvalidArgumentException('The icon registry needs an "icons" object and an optional "themes" object');
        }

        return new self($document['icons'], $document['themes'] ?? []);
    }

    public function has(string $name): bool
    {
        return isset($this->icons[$name]);
    }

    /** The space-separated classes that draw $name in $theme. */
    public function classes(string $name, string $theme): string
    {
        if (!isset($this->icons[$name])) {
            throw new \InvalidArgumentException('Unknown icon: ' . $name);
        }

        return $this->themes[$theme][$name] ?? $this->icons[$name];
    }

    /**
     * Every name resolved for $theme. An uninstalled theme gets the defaults.
     *
     * @return array<string, string>
     */
    public function forTheme(string $theme): array
    {
        return array_replace($this->icons, $this->themes[$theme] ?? []);
    }

    /** @param array<mixed> $entries */
    private static function checkEntries(array $entries, string $where): void
    {
        foreach ($entries as $name => $classes) {
            if (!is_string($name) || preg_match(self::NAME, $name) !== 1) {
                throw new \InvalidArgumentException("Invalid icon name in $where: $name");
            }
            if (!is_string($classes) || preg_match(self::CLASSES, $classes) !== 1) {
                throw new \InvalidArgumentException("Icon $name in $where needs a space-separated class list");
            }
        }
    }
}
