<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\LegacyRequestContext {

    use Kadupul\Platform\Infrastructure\Legacy\LegacyRequestContext;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\Process\Process;

    require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

    final class LegacyRequestContextTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            if (!function_exists('sanitize_uri')) {
                eval('function sanitize_uri(string $uri): string { return "sanitized:" . $uri; }');
            }
            if (!function_exists('cacti_log')) {
                eval('function cacti_log(string $message): void { $GLOBALS["legacy_request_context_logs"][] = $message; }');
            }

            $source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
            if (!is_string($source)) {
                self::fail('Unable to read lib/functions.php.');
            }

            eval(test_php_function_source($source, 'get_current_page'));
            eval(test_php_function_source($source, 'get_browser_query_string'));
        }

        public function testCurrentPageUsesScriptNameThenFilenameAndPreservesFullPathOption(): void
        {
            $context = new LegacyRequestContext();
            $request = new Request(server: ['SCRIPT_NAME' => '/admin/index.php', 'SCRIPT_FILENAME' => '/srv/fallback.php']);

            self::assertSame('index.php', $context->currentPage($request));
            self::assertSame('/admin/index.php', $context->currentPage($request, false));

            $fallback = new Request(server: ['SCRIPT_NAME' => '', 'SCRIPT_FILENAME' => '/srv/fallback.php']);
            self::assertSame('fallback.php', $context->currentPage($fallback));
            self::assertSame('/srv/fallback.php', $context->currentPage($fallback, false));
        }

        public function testCurrentPageReturnsFalseWhenNoScriptValueExists(): void
        {
            self::assertFalse((new LegacyRequestContext())->currentPage(new Request()));
        }

        public function testBrowserQueryStringReturnsOnlyTheRawRequestUri(): void
        {
            $context = new LegacyRequestContext();
            $request = new Request(server: [
                'REQUEST_URI' => '/graphs.php?x=1&y=2',
                'SCRIPT_NAME' => '/index.php',
                'QUERY_STRING' => 'ignored=1',
            ]);

            self::assertSame('/graphs.php?x=1&y=2', $context->browserQueryString($request));

            $fallback = new Request(server: ['SCRIPT_NAME' => '/index.php', 'QUERY_STRING' => 'x=1&y=2']);
            self::assertSame('', $context->browserQueryString($fallback));
            self::assertSame('', $context->browserQueryString(new Request(server: ['SCRIPT_NAME' => '/index.php'])));
        }

        public function testLegacyWrappersRetainSanitizationAndMissingPageLogBehavior(): void
        {
            $source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
            self::assertIsString($source);

            $script = 'require ' . var_export(dirname(__DIR__, 2) . '/include/vendor/autoload.php', true) . ';'
                . 'function sanitize_uri($uri) { return "sanitized:" . $uri; }'
                . 'function cacti_log($message) { $GLOBALS["logs"][] = $message; }'
                . 'eval(' . var_export(test_php_function_source($source, 'get_current_page'), true) . ');'
                . 'eval(' . var_export(test_php_function_source($source, 'get_browser_query_string'), true) . ');'
                . '$_SERVER = ["REQUEST_URI" => "/raw.php?a=1&b=2"];'
                . '$uri = get_browser_query_string();'
                . '$_SERVER = [];'
                . '$page = get_current_page();'
                . '$missingUri = get_browser_query_string();'
                . 'echo json_encode([$uri, $page, $missingUri, $GLOBALS["logs"]], JSON_THROW_ON_ERROR);';
            $process = new Process([PHP_BINARY, '-r', $script]);
            $process->run();

            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame(
                [
                    'sanitized:/raw.php?a=1&b=2',
                    false,
                    'sanitized:',
                    ['ERROR: unable to determine current_page', 'ERROR: unable to determine current_page'],
                ],
                json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)
            );
        }

        public function testLegacyWrappersExecuteRequestContextBranchesInProcess(): void
        {
            $server = $_SERVER;
            $hadLogs = array_key_exists('legacy_request_context_logs', $GLOBALS);
            $logs = $GLOBALS['legacy_request_context_logs'] ?? null;

            try {
                $_SERVER = [
                    'REQUEST_URI' => '/graphs.php?x=1&y=2',
                    'SCRIPT_NAME' => '/admin/graphs.php',
                    'QUERY_STRING' => 'ignored=1',
                ];

                self::assertSame('sanitized:/graphs.php?x=1&y=2', get_browser_query_string());
                self::assertSame('graphs.php', get_current_page());

                $_SERVER = ['SCRIPT_NAME' => '/index.php', 'QUERY_STRING' => 'x=1&y=2'];
                self::assertSame('sanitized:index.php?x=1&y=2', get_browser_query_string());

                $_SERVER = [];
                self::assertFalse(get_current_page());
                self::assertSame('sanitized:', get_browser_query_string());
                self::assertSame(
                    ['ERROR: unable to determine current_page', 'ERROR: unable to determine current_page'],
                    $GLOBALS['legacy_request_context_logs']
                );
            } finally {
                $_SERVER = $server;
                if ($hadLogs) {
                    $GLOBALS['legacy_request_context_logs'] = $logs;
                } else {
                    unset($GLOBALS['legacy_request_context_logs']);
                }
            }
        }

        public function testWrappersFallBackWhenSymfonyExistsWithoutKadupulAutoloading(): void
        {
            $source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
            self::assertIsString($source);

            $script = 'namespace Symfony\\Component\\HttpFoundation { class Request {} }'
                . 'namespace { function sanitize_uri($uri) { return "sanitized:" . $uri; }'
                . 'function cacti_log($message) { $GLOBALS["logs"][] = $message; }'
                . 'eval(' . var_export(test_php_function_source($source, 'get_current_page'), true) . ');'
                . 'eval(' . var_export(test_php_function_source($source, 'get_browser_query_string'), true) . ');'
                . '$_SERVER = ["REQUEST_URI" => "/early.php?a=1", "SCRIPT_NAME" => "/early.php"];'
                . 'echo json_encode([get_browser_query_string(), get_current_page()], JSON_THROW_ON_ERROR); }';
            $process = new Process([PHP_BINARY, '-r', $script]);
            $process->run();

            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame(
                ['sanitized:/early.php?a=1', 'early.php'],
                json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)
            );
        }

        public function testLegacyWrappersKeepWorkingBeforeComposerAutoloadIsRegistered(): void
        {
            $source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
            self::assertIsString($source);

            $script = 'function sanitize_uri($uri) { return "sanitized:" . $uri; }'
                . 'function cacti_log($message) { $GLOBALS["logs"][] = $message; }'
                . 'eval(' . var_export(test_php_function_source($source, 'get_current_page'), true) . ');'
                . 'eval(' . var_export(test_php_function_source($source, 'get_browser_query_string'), true) . ');'
                . '$_SERVER = ["REQUEST_URI" => "/early.php?a=1", "SCRIPT_NAME" => "/early.php"];'
                . '$uri = get_browser_query_string();'
                . '$page = get_current_page();'
                . '$_SERVER = ["SCRIPT_NAME" => "", "SCRIPT_FILENAME" => "/srv/fallback.php", "QUERY_STRING" => "x=1"];'
                . '$fallbackUri = get_browser_query_string();'
                . '$fullPath = get_current_page(false);'
                . '$_SERVER = [];'
                . '$missingPage = get_current_page();'
                . '$missingUri = get_browser_query_string();'
                . 'echo json_encode([$uri, $page, $fallbackUri, $fullPath, $missingPage, $missingUri, $GLOBALS["logs"] ?? []], JSON_THROW_ON_ERROR);';
            $process = new Process([PHP_BINARY, '-r', $script]);
            $process->run();

            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame(
                ['sanitized:/early.php?a=1', 'early.php', 'sanitized:fallback.php?x=1', '/srv/fallback.php', false, 'sanitized:', ['ERROR: unable to determine current_page', 'ERROR: unable to determine current_page']],
                json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)
            );
        }
    }
}
