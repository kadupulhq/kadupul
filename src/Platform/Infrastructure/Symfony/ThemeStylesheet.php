<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\Platform\Contract\DatabaseConnection;
use Twig\Attribute\AsTwigFunction;

/** Selects the theme stylesheet the signed-in user would get on a legacy page. */
final class ThemeStylesheet
{
    private const string DEFAULT_THEME = 'modern';

    private ?string $path = null;

    public function __construct(
        private readonly AuthenticatedAccess $access,
        private readonly DatabaseConnection $database,
        private readonly string $projectDir,
    ) {}

    /** Logical path of the theme's main.css, for asset(path, 'legacy'). */
    #[AsTwigFunction('theme_stylesheet')]
    public function path(): string
    {
        return $this->path ??= 'include/themes/' . $this->theme() . '/main.css';
    }

    private function theme(): string
    {
        try {
            $actor = $this->access->authenticatedActor();
        } catch (\RuntimeException) {
            $actor = null;
        }
        // Layout pages are served only to signed-in accounts; without one,
        // no settings are read and the default theme applies.
        if ($actor === null) {
            return self::DEFAULT_THEME;
        }
        $candidates = [
            $this->value("SELECT value FROM settings_user WHERE name = 'selected_theme' AND user_id = ?", [$actor->id]),
            $this->value("SELECT value FROM settings WHERE name = 'selected_theme'", []),
        ];

        foreach ($candidates as $theme) {
            // Only an installed theme directory may reach a filesystem path.
            if (is_string($theme) && preg_match('/\A[a-z0-9_-]+\z/D', $theme) === 1
                && is_file($this->projectDir . '/include/themes/' . $theme . '/main.css')) {
                return $theme;
            }
        }

        return self::DEFAULT_THEME;
    }

    /** @param list<int> $parameters */
    private function value(string $sql, array $parameters): mixed
    {
        try {
            $query = $this->database->get()->prepare($sql);
            $query->execute($parameters);

            return $query->fetchColumn();
        } catch (\RuntimeException) {
            // A pre-1.x schema has no settings_user, as in get_selected_theme(),
            // and an unreachable database leaves the default theme.
            return false;
        }
    }
}
