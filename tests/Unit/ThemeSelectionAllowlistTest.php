<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

/*
 * Runs the real get_selected_theme() and cacti_validate_theme() from
 * lib/functions.php in a child process. Other test files load or stub
 * lib/functions.php, and cacti_validate_theme() caches its allowlist in a
 * static, so each case needs a fresh interpreter.
 */
final class ThemeSelectionAllowlistTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kadupul-theme-allowlist-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/include/themes', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testInstalledSessionThemeIsReturnedWithoutTouchingTheDatabase(): void
    {
        $this->installTheme('dark', true);
        $this->installTheme('modern', true);

        $state = $this->selectTheme(
            array('dark' => 'Dark', 'modern' => 'Modern'),
            array('selected_theme' => 'dark', 'sess_user_id' => 7),
            'modern',
            'modern'
        );

        self::assertSame('dark', $state['theme']);
        self::assertSame(array(), $state['updates']);
        self::assertSame(0, $state['lookups']);
    }

    public function testInstalledUserThemeIsReturnedAndCachedInTheSession(): void
    {
        $this->installTheme('dark', true);
        $this->installTheme('modern', true);

        $state = $this->selectTheme(
            array('dark' => 'Dark', 'modern' => 'Modern'),
            array('sess_user_id' => 7),
            'modern',
            'dark'
        );

        self::assertSame('dark', $state['theme']);
        self::assertSame('dark', $state['session']['selected_theme']);
        self::assertSame(array(), $state['updates']);
    }

    public function testStoredThemeOutsideTheInstalledListFallsBackAndRepairsTheUserRow(): void
    {
        $this->installTheme('classic', true);
        $this->installTheme('modern', true);
        // A main.css outside include/themes is what made the old file_exists
        // check accept a traversal value.
        mkdir($this->root . '/evil', 0700);
        file_put_contents($this->root . '/evil/main.css', '');

        $state = $this->selectTheme(
            array('classic' => 'Classic', 'modern' => 'Modern'),
            array('sess_user_id' => 7),
            'modern',
            '../../evil'
        );

        self::assertSame('classic', $state['theme']);
        self::assertSame('classic', $state['session']['selected_theme']);
        self::assertSame(array(array('classic', 7)), $state['updates']);
    }

    public function testSessionThemeOutsideTheInstalledListIsIgnored(): void
    {
        $this->installTheme('modern', true);
        mkdir($this->root . '/evil', 0700);
        file_put_contents($this->root . '/evil/main.css', '');

        foreach (array('../../evil', array('modern'), 'Modern') as $requested) {
            $state = $this->selectTheme(
                array('modern' => 'Modern'),
                array('selected_theme' => $requested),
                'modern',
                ''
            );

            self::assertSame('modern', $state['theme']);
            self::assertSame('modern', $state['session']['selected_theme']);
            self::assertSame(array(), $state['updates']);
        }
    }

    public function testInstalledThemeWithoutAStylesheetIsSkipped(): void
    {
        $this->installTheme('classic', false);
        $this->installTheme('paw', true);

        $state = $this->selectTheme(
            array('classic' => 'Classic', 'paw' => 'Paw'),
            array('sess_user_id' => 3),
            'classic',
            ''
        );

        self::assertSame('paw', $state['theme']);
        self::assertSame(array(array('paw', 3)), $state['updates']);
    }

    public function testFallbackWithoutALoggedInUserWritesNothing(): void
    {
        $this->installTheme('modern', true);

        $state = $this->selectTheme(array('modern' => 'Modern'), array(), 'invalid/theme', '');

        self::assertSame('modern', $state['theme']);
        self::assertSame(array(), $state['updates']);
    }

    public function testNoUsableThemeFallsBackToAFixedName(): void
    {
        $withClassic = $this->selectTheme(array('classic' => 'Classic', 'dark' => 'Dark'), array(), '../x', '');
        self::assertSame('classic', $withClassic['theme']);

        $withoutClassic = $this->selectTheme(array('dark' => 'Dark'), array(), '../x', '');
        self::assertSame('dark', $withoutClassic['theme']);

        $empty = $this->selectTheme(array(), array(), '../x', '');
        self::assertSame('modern', $empty['theme']);
    }

    public function testValidRequestedGraphThemeIsReturned(): void
    {
        $this->installTheme('modern', true);
        $this->installTheme('dark', true);

        self::assertSame('dark', $this->validateTheme('dark', 'modern'));
    }

    public function testConfiguredDefaultIsUsedWhenItIsInstalled(): void
    {
        $this->installTheme('modern', true);
        $this->installTheme('dark', true);

        self::assertSame('dark', $this->validateTheme('../../etc/passwd', 'dark'));
    }

    public function testConfiguredDefaultOutsideTheInstalledListIsReplaced(): void
    {
        $this->installTheme('modern', true);
        $this->installTheme('dark', true);
        mkdir($this->root . '/evil', 0700);
        file_put_contents($this->root . '/evil/rrdtheme.php', '<?php');

        self::assertSame('modern', $this->validateTheme('attacker', '../../evil'));
        self::assertSame('modern', $this->validateTheme('attacker', 'evil'));
        self::assertSame('modern', $this->validateTheme('attacker', ''));
    }

    public function testInvalidDefaultWithoutModernUsesTheFirstInstalledTheme(): void
    {
        $this->installTheme('sunrise', true);
        $this->installTheme('dark', true);

        self::assertSame('dark', $this->validateTheme('attacker', 'invalid/default'));
    }

    public function testInvalidDefaultWithNoInstalledThemeUsesModern(): void
    {
        self::assertSame('modern', $this->validateTheme('attacker', 'invalid/default'));
    }

    private function installTheme(string $name, bool $withStylesheet): void
    {
        $directory = $this->root . '/include/themes/' . $name;
        mkdir($directory, 0700);
        file_put_contents($directory . '/rrdtheme.php', '<?php');

        if ($withStylesheet) {
            file_put_contents($directory . '/main.css', '');
        }
    }

    /**
     * @param array<string, string> $themes
     * @param array<string, mixed>  $session
     *
     * @return array{theme: mixed, session: array<string, mixed>, updates: list<array<int, mixed>>, lookups: int}
     */
    private function selectTheme(array $themes, array $session, string $configured, string $userTheme): array
    {
        $script = $this->stubs($configured)
            . 'function db_table_exists($table) { return true; }'
            . 'function db_fetch_cell_prepared($query, $params) { $GLOBALS["lookups"]++; return ' . var_export($userTheme, true) . '; }'
            . 'function db_execute_prepared($query, $params) { $GLOBALS["updates"][] = $params; return true; }'
            . $this->functionSource('get_selected_theme')
            . '$GLOBALS["themes"] = ' . var_export($themes, true) . ';'
            . '$GLOBALS["updates"] = array();'
            . '$GLOBALS["lookups"] = 0;'
            . '$_SESSION = ' . var_export($session, true) . ';'
            . '$theme = get_selected_theme();'
            . 'echo json_encode(array("theme" => $theme, "session" => $_SESSION, "updates" => $GLOBALS["updates"], "lookups" => $GLOBALS["lookups"]), JSON_THROW_ON_ERROR);';

        return json_decode($this->runPhp($script), true, flags: JSON_THROW_ON_ERROR);
    }

    private function validateTheme(string $requested, string $configured): string
    {
        $script = $this->stubs($configured)
            . $this->functionSource('cacti_validate_theme')
            . 'echo cacti_validate_theme(' . var_export($requested, true) . ');';

        return $this->runPhp($script);
    }

    private function stubs(string $configured): string
    {
        return '$GLOBALS["config"] = array("base_path" => ' . var_export($this->root, true) . ');'
            . 'function read_config_option($name) { return ' . var_export($configured, true) . '; }';
    }

    private function functionSource(string $name): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
        self::assertIsString($source);

        return test_php_function_source($source, $name);
    }

    private function runPhp(string $script): string
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $error);
        self::assertSame('', $error);
        self::assertIsString($output);

        return $output;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (file_exists($path)) {
                unlink($path);
            }

            return;
        }

        foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }
}
