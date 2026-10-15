<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace CactiHtmlContextEscapeBehavior;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
if (!is_string($source)) {
    throw new \RuntimeException('Unable to read escaping helper.');
}
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'cacti_html_context_escape')); // nosemgrep: php.lang.security.eval-use.eval-use

/*
 * Behavior tests for cacti_html_context_escape().
 *
 * Root-cause mitigation for context-confusion XSS across the codebase.
 * Each context must reject the attacker-controlled characters that can
 * break out of that specific embedding.
 */

// Load the real context values without defining unrelated application constants
// in the shared runner. Other security suites define their own fixture constants.
$constants = \test_php_run('require ' . var_export(dirname(__DIR__, 2) . '/include/global_constants.php', true)
    . '; echo json_encode(array_intersect_key(get_defined_constants(), array_flip(["CACTI_ESC_ELEMENT", "CACTI_ESC_ATTR", "CACTI_ESC_JS_STRING", "CACTI_ESC_URL", "CACTI_ESC_CSS"])), JSON_THROW_ON_ERROR);');
if ($constants['status'] !== 0 || $constants['err'] !== '') {
    throw new \RuntimeException('Unable to load production escaping contexts.');
}
$contexts = json_decode($constants['out'], true, 512, JSON_THROW_ON_ERROR);
if (count($contexts) !== 5) {
    throw new \RuntimeException('Missing production escaping context.');
}
foreach ($contexts as $name => $value) {
    define(__NAMESPACE__ . '\\' . $name, $value);
}

describe('CACTI_ESC_ELEMENT — HTML element content', function () {
    it('escapes the five HTML metacharacters', function () {
        $out = cacti_html_context_escape('<script>alert(1)</script>', CACTI_ESC_ELEMENT);
        expect($out)->toBe('&lt;script&gt;alert(1)&lt;/script&gt;');
    });

    it('escapes both quote styles', function () {
        expect(cacti_html_context_escape('a"b\'c', CACTI_ESC_ELEMENT))
            ->toBe('a&quot;b&#039;c');
    });

    it('escapes ampersand', function () {
        expect(cacti_html_context_escape('a&b', CACTI_ESC_ELEMENT))
            ->toBe('a&amp;b');
    });

    it('preserves UTF-8 multi-byte characters', function () {
        expect(cacti_html_context_escape('café', CACTI_ESC_ELEMENT))->toBe('café');
    });
});

describe('CACTI_ESC_ATTR — HTML attribute value', function () {
    it('escapes both quote delimiters', function () {
        $out = cacti_html_context_escape('a" onmouseover="evil()"', CACTI_ESC_ATTR);
        expect($out)->not->toContain('"')
            ->and($out)->toContain('&quot;');
    });

    it('blocks single-quote breakout', function () {
        $out = cacti_html_context_escape("a' onmouseover='evil()'", CACTI_ESC_ATTR);
        expect($out)->not->toContain("'")
            ->and($out)->toContain('&#039;');
    });

    it('blocks angle-bracket attribute breakout', function () {
        $out = cacti_html_context_escape('a><script>alert(1)</script>', CACTI_ESC_ATTR);
        expect($out)->not->toContain('<')
            ->and($out)->not->toContain('>');
    });
});

describe('CACTI_ESC_JS_STRING — JavaScript string literal', function () {
    it('escapes double quote to prevent string breakout', function () {
        $out = cacti_html_context_escape('";alert(1);//', CACTI_ESC_JS_STRING);
        expect($out)->not->toContain('"');
    });

    it('escapes single quote (for single-quoted JS literals)', function () {
        $out = cacti_html_context_escape("';alert(1);//", CACTI_ESC_JS_STRING);
        expect($out)->not->toContain("'");
    });

    it('escapes backslash to prevent escape-sequence injection', function () {
        $out = cacti_html_context_escape('\\', CACTI_ESC_JS_STRING);
        expect($out)->toBe('\\\\');
    });

    it('escapes angle brackets so <script> / </script> cannot close a block', function () {
        $out = cacti_html_context_escape('</script><script>alert(1)</script>', CACTI_ESC_JS_STRING);
        expect($out)->not->toContain('<')
            ->and($out)->not->toContain('>');
    });

    it('escapes ampersand so HTML entity injection does not decode', function () {
        $out = cacti_html_context_escape('a & b', CACTI_ESC_JS_STRING);
        expect($out)->toBe('a \\u0026 b');
    });

    it('escapes newlines and carriage returns (break string literals)', function () {
        $out = cacti_html_context_escape("line1\nline2", CACTI_ESC_JS_STRING);
        expect($out)->not->toContain("\n");

        $out = cacti_html_context_escape("line1\rline2", CACTI_ESC_JS_STRING);
        expect($out)->not->toContain("\r");
    });

    it('produces no surrounding quotes (caller owns delimiters)', function () {
        $out = cacti_html_context_escape('abc', CACTI_ESC_JS_STRING);
        expect(substr($out, 0, 1))->not->toBe('"');
        expect(substr($out, -1))->not->toBe('"');
    });

    it('returns empty string on json_encode failure (fail closed)', function () {
        // Invalid UTF-8 byte sequence — json_encode returns false.
        $out = cacti_html_context_escape("\xB1\x31", CACTI_ESC_JS_STRING);
        // Should not be the raw input.
        expect($out)->toBe('');
    });
});

describe('CACTI_ESC_URL — URL component', function () {
    it('encodes reserved characters', function () {
        expect(cacti_html_context_escape('a b', CACTI_ESC_URL))->toBe('a%20b');
        expect(cacti_html_context_escape('a&b', CACTI_ESC_URL))->toBe('a%26b');
        expect(cacti_html_context_escape('a=b', CACTI_ESC_URL))->toBe('a%3Db');
        expect(cacti_html_context_escape('a?b', CACTI_ESC_URL))->toBe('a%3Fb');
        expect(cacti_html_context_escape('a#b', CACTI_ESC_URL))->toBe('a%23b');
    });

    it('uses %20 for space, not + (rawurlencode vs urlencode)', function () {
        $out = cacti_html_context_escape('hello world', CACTI_ESC_URL);
        expect($out)->toContain('%20')->and($out)->not->toContain('+');
    });
});

describe('CACTI_ESC_CSS — CSS string value', function () {
    it('escapes non-alphanumeric characters as hex', function () {
        $out = cacti_html_context_escape('red; color:', CACTI_ESC_CSS);
        // ; and : and space must be hex-escaped.
        expect($out)->not->toContain(';')
            ->and($out)->not->toContain(':');
    });

    it('preserves alphanumerics, hyphen, underscore', function () {
        expect(cacti_html_context_escape('red_color-name1', CACTI_ESC_CSS))
            ->toBe('red_color-name1');
    });
});

describe('unknown context', function () {
    it('falls back to HTML-element escape (fail closed)', function () {
        $out = cacti_html_context_escape('<script>', 'bogus');
        // Must NOT be the raw input; must be HTML-escaped.
        expect($out)->toBe('&lt;script&gt;');
    });
});

describe('type coercion', function () {
    it('stringifies non-string input', function () {
        expect(cacti_html_context_escape(42, CACTI_ESC_ELEMENT))->toBe('42');
        expect(cacti_html_context_escape(true, CACTI_ESC_ELEMENT))->toBe('1');
        expect(cacti_html_context_escape(null, CACTI_ESC_ELEMENT))->toBe('');
    });
});
