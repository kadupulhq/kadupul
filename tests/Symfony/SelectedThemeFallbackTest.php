<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Tests\ThemeSelectedFallback;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

function read_config_option(string $name)
{
    return $GLOBALS['theme_selected_fallback']['configured'];
}

function db_table_exists(string $table): bool
{
    return true;
}

function db_fetch_cell_prepared(string $query, array $params)
{
    return $GLOBALS['theme_selected_fallback']['user_theme'];
}

function db_execute_prepared(string $query, array $params): bool
{
    $GLOBALS['theme_selected_fallback']['updates'][] = $params;

    return true;
}

function file_exists(string $path): bool
{
    return in_array($path, $GLOBALS['theme_selected_fallback']['files'], true);
}

$source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
if ($source === false) {
    throw new \RuntimeException('Unable to read lib/functions.php');
}

eval('namespace Kadupul\\Tests\\ThemeSelectedFallback;' . \test_php_function_source($source, 'get_selected_theme')); // nosemgrep: php.lang.security.eval-use.eval-use

final class SelectedThemeFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['config'] = ['base_path' => '/cacti'];
        $GLOBALS['themes'] = ['classic' => 'Classic'];
        $GLOBALS['theme_selected_fallback'] = [
            'configured' => 'invalid/theme',
            'user_theme' => '../../etc/passwd',
            'files' => [],
            'updates' => [],
        ];
        $_SESSION = ['sess_user_id' => 7];
    }

    public function testInvalidPersistedThemeFallsBackToClassicWhenNoThemeHasAStylesheet(): void
    {
        self::assertSame('classic', get_selected_theme());
        self::assertSame('classic', $_SESSION['selected_theme']);
        self::assertSame([['classic', 7]], $GLOBALS['theme_selected_fallback']['updates']);
    }

    public function testInvalidConfiguredThemeFallsBackWithoutLoggedInUser(): void
    {
        $GLOBALS['theme_selected_fallback']['user_theme'] = '';
        $_SESSION = [];

        self::assertSame('classic', get_selected_theme());
        self::assertSame('classic', $_SESSION['selected_theme']);
        self::assertSame([], $GLOBALS['theme_selected_fallback']['updates']);
    }

    public function testInvalidScalarAndNonScalarSessionThemesFallBackSafely(): void
    {
        $GLOBALS['theme_selected_fallback']['user_theme'] = '';
        unset($_SESSION['sess_user_id']);

        foreach (['../../etc/passwd', ['unexpected']] as $requestedTheme) {
            $_SESSION['selected_theme'] = $requestedTheme;

            self::assertSame('classic', get_selected_theme());
            self::assertSame('classic', $_SESSION['selected_theme']);
        }

        self::assertSame([], $GLOBALS['theme_selected_fallback']['updates']);
    }
}
