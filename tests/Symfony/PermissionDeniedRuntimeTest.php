<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\PermissionDeniedRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The worker's side of the page against the real validate_redirect_url(),
 * is_page_ajax() and plugin constants. Each test runs alone because the
 * production lib/ files declare global functions.
 */
final class PermissionDeniedRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/include/global_constants.php';
    }

    /** @param mixed $hookResult what custom_denied returns @param ?string $printed what it prints */
    private static function capture(array $server, mixed $hookResult = null, ?string $printed = null, bool $rewrite = false, bool $close = false): array
    {
        $root = dirname(__DIR__, 2);
        $GLOBALS['denied_fixture'] = ['result' => $hookResult ?? OPER_MODE_NATIVE, 'printed' => $printed, 'close' => $close, 'calls' => []];
        $GLOBALS['csrf'] = ['disable' => false, 'rewrite' => $rewrite];
        eval(<<<'PHP'
            class CactiSecureHeaders
            {
                public static function contentSecurityPolicies()
                {
                    return ['Content-Security-Policy' => "default-src 'self'; script-src 'nonce-fixture'"];
                }
            }

            function api_plugin_hook_function($name, $parameter = null)
            {
                $GLOBALS['denied_fixture']['calls'][] = [$name, $parameter];
                print $GLOBALS['denied_fixture']['printed'] ?? '';
                if ($GLOBALS['denied_fixture']['close']) {
                    ob_end_clean();
                }

                return $GLOBALS['denied_fixture']['result'];
            }

            function db_fetch_cell($sql)
            {
                return " 1.3.0\n";
            }

            function csrf_ob_handler($buffer, $flags)
            {
                return '<!--csrf-->' . $buffer;
            }
            PHP);
        // Execute the production helpers so the redirect rules are the real ones.
        require_once $root . '/lib/functions.php';
        require_once $root . '/lib/html_utility.php';
        $_SERVER = $server + ['SERVER_NAME' => 'example.test', 'SERVER_PORT' => '443'];
        $_REQUEST = $_GET = [];

        return (new PermissionDeniedRuntime())->capture();
    }

    public static function referers(): iterable
    {
        yield 'absent' => [null, null];
        yield 'local page' => ['https://example.test/kadupul/host.php?action=edit&id=3', '/kadupul/host.php?action=edit&id=3'];
        yield 'empty header' => ['', 'index.php'];
        yield 'foreign host' => ['https://foreign.invalid/kadupul/host.php', 'index.php'];
        yield 'protocol relative' => ['//foreign.invalid/', 'index.php'];
        yield 'script scheme' => ['javascript:alert(1)', 'index.php'];
        yield 'credentials' => ['https://user:pass@example.test/', 'index.php'];
        yield 'other port' => ['https://example.test:8443/kadupul/', 'index.php'];
        yield 'traversal' => ['https://example.test/kadupul/../etc/passwd', 'index.php'];
        yield 'markup' => ['https://example.test/kadupul/host.php?x="><b>', '/kadupul/host.php?x='];
    }

    #[DataProvider('referers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefererPassesThroughTheNativeRedirectValidation(?string $referer, ?string $expected): void
    {
        $result = self::capture($referer === null ? [] : ['HTTP_REFERER' => $referer]);
        self::assertSame($expected, $result['return_to']);
        self::assertNull($result['replacement']);
        self::assertFalse($result['ajax']);
        self::assertSame('1.3.0', $result['version']);
        self::assertSame(COPYRIGHT_YEARS_SHORT, $result['copyright']);
        self::assertSame([['custom_denied', OPER_MODE_NATIVE]], $GLOBALS['denied_fixture']['calls']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReskinReturnsThePrintedPageThroughTheCsrfRewriter(): void
    {
        $result = self::capture(['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], OPER_MODE_RESKIN, '<html><form></form></html>', true);
        self::assertSame('<!--csrf--><html><form></form></html>', $result['replacement']);
        self::assertTrue($result['ajax']);
        self::assertSame(['Content-Security-Policy' => "default-src 'self'; script-src 'nonce-fixture'"], $result['policies']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReskinWithoutRewritingKeepsThePrintedPage(): void
    {
        self::assertSame('<p>custom</p>', self::capture([], OPER_MODE_RESKIN, '<p>custom</p>')['replacement']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnlyAStrictReskinReplacesTheCorePage(): void
    {
        // permission_denied.php compared with ===, so a string "1" stays native.
        $result = self::capture(['HTTP_X_REQUESTED_WITH' => 'fetch'], '1', '<p>ignored</p>');
        self::assertNull($result['replacement']);
        self::assertFalse($result['ajax']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExtensionThatClosesTheBufferFails(): void
    {
        $level = ob_get_level();
        try {
            self::capture([], OPER_MODE_RESKIN, '<p>partial</p>', false, true);
            self::fail('A hook that ends the runtime buffer must not produce a page.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('output boundary', $error->getMessage());
        }
        // The hook closed the runtime's buffer; the runtime must not close PHPUnit's.
        self::assertSame($level, ob_get_level());
    }
}
