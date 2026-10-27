<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Ported from Cacti 1.2.x tests/fixtures/Core/ScriptSubstitutionMatrix.php
 * (Cacti PR #8238, GHSA-5v3j-wcrr-jxjg). Loaded after lib/functions.php in a
 * child PHP process. Returns the number of checked cases; any mismatch throws.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function script_substitution_matrix(): int
{
    $check = static function ($actual, $expected, string $case): void {
        if ($actual !== $expected) {
            throw new RuntimeException('Substitution failed: ' . $case . ' ' . var_export($actual, true));
        }
    };

    // Parser boundaries without a shell: malformed and unknown placeholders
    // remain literal, raw paths keep their quoting, values are single-pass.
    $cases = [
        ['', [], ''],
        ['plain', [], 'plain'],
        ['<unknown>', [], '<unknown>'],
        ['"<unknown>"', [], '"<unknown>"'],
        ['<bad-name>', [], '<bad-name>'],
        ['<unterminated', [], '<unterminated'],
        ['<0_>', ['0_' => 'ok'], 'ok'],
        ['<a><b>', ['a' => '<b>', 'b' => 'safe'], '<b>safe'],
        ['"<path>"', ['path' => '/space path/php'], '"/space path/php"'],
        ['<path>/script.php', ['path' => '/space path'], '/space path/script.php'],
        ['<a>', ['a' => ''], ''],
        ['<a>', ['a' => "'"], "'"],
        ['<a>', ['a' => "'raw"], "'raw"],
        ['<a>', ['a' => '"raw'], '"raw'],
        ['<a>', ['a' => 'raw"'], 'raw"'],
        ['\\', [], '\\'],
        ['"\\', [], '"\\'],
        ['"\\x"', [], '"\\x"'],
        ['\\x', [], '\\x'],
        ['\\"', [], '\\"'],
        ["\\'", [], "\\'"],
        ['"\\\'"', [], '"\\\'"'],
        ["'a'", [], "'a'"],
        ['"a"', [], '"a"'],
        ['<a>', ['a' => '"safe"'], '"safe"'],
        // $(...) and backticks are nested contexts with their own quoting;
        // \$ and \` are escaped and open nothing.
        ['"$(printf %s <field>)"', ['field' => "'p'"], '"$(printf %s \'p\')"'],
        ['$(printf %s <field>)', ['field' => "'p'"], '$(printf %s \'p\')'],
        ['"`printf %s <field>`"', ['field' => "'p'"], '"`printf %s \'p\'`"'],
        ['`printf %s <field>`', ['field' => "'p'"], '`printf %s \'p\'`'],
        ['"\\$(<field>)"', ['field' => "'p'"], '"\\$("\'p\'")"'],
        ['"\\`<field>"', ['field' => "'p'"], '"\\`"\'p\'""'],
        ['\\$(<field>)', ['field' => "'p'"], '\\$(\'p\')'],
        ['\\`<field>', ['field' => "'p'"], '\\`\'p\''],
        // Unsupported nested shell grammar rejects the complete template.
        ['"$( (true); printf %s <field>)"', ['field' => "'p'"], ''],
        ['"$( ((true)); printf %s <field>)"', ['field' => "'p'"], ''],
        ['"$(case x in x) printf %s <field>;; esac)"', ['field' => "'p'"], ''],
        ['"$(printf %s <field>; (true))"', ['field' => "'p'"], ''],
        ["\"\$(printf %s <field> # )\n)\"", ['field' => "'p'"], ''],
        ["\"\$(cat <<EOF\n<field>\nEOF\n)\"", ['field' => "'p'"], ''],
        ['"$(printf %s < /dev/null; printf %s <field>)"', ['field' => "'p'"], ''],
        ['"$(printf %s > /dev/null; printf %s <field>)"', ['field' => "'p'"], ''],
        ['"`(true); printf %s <field>`"', ['field' => "'p'"], ''],
        ['"$((1 + <field>))"', ['field' => "'p'"], ''],
        // The shell unescapes backslashes inside backticks before parsing the
        // inner command, and ${...} words have their own quoting rules.
        ['`printf "%s" \\\\"<field>\\\\"`', ['field' => "'p'"], ''],
        ['`printf %s \\\\\'<field>\\\'`', ['field' => "'p'"], ''],
        ['`printf %s $(printf `x`) <field>`', ['field' => "'p'"], ''],
        ['"${X:-<field>}"', ['field' => "'p'"], ''],
        ['${X:-x<field>}', ['field' => "'p'"], ''],
        ['"${X:-\\}}" <field>', ['field' => "'p'"], ''],
        // bash's $'...' and $"..." honor backslash escapes the scanner does not model.
        ["\$'x\\'<field>'", ['field' => "'p'"], ''],
        ['$"<field>"', ['field' => "'p'"], ''],
        ['printf %s $\'a\' <field>', ['field' => "'p'"], ''],
        // Inside double quotes $' is literal text.
        ['"$\'<field>\'"', ['field' => "'p'"], '"$\'"\'p\'"\'"'],
        // Parameter expansions without fields or backslashes are kept as written.
        ['"${HOME:-/tmp}" <field>', ['field' => "'p'"], '"${HOME:-/tmp}" \'p\''],
        ['${X#*/} "<field>"', ['field' => "'p'"], '${X#*/} \'p\''],
        ["\"\${X:-'}'}\" <field>", ['field' => "'p'"], "\"\${X:-'}'}\" 'p'"],
    ];

    foreach ($cases as $index => [$template, $values, $expected]) {
        $check(\Kadupul\DataInput\Domain\InputStringSubstitution::substitute($template, $values), $expected, 'parser ' . $index);
    }

    $directory = sys_get_temp_dir() . '/kadupul-shell-matrix-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $marker   = $directory . '/executed';
    $payloads = ['', '0', 'hello world', "single'quote", '"double"', 'line' . "\n" . 'break', 'a\\b', '<other>',
        '$(touch ' . escapeshellarg($marker) . ')', '`touch ' . escapeshellarg($marker) . '`',
        '; touch ' . escapeshellarg($marker) . '; #', '${HOME}', '* ? [abc]', 'é 日本語'];
    $count = count($cases);

    try {
        foreach ($payloads as $value) {
            foreach (['', "'", '"'] as $quote) {
                foreach (['exact', 'prefix', 'suffix', 'embedded', 'adjacent'] as $shape) {
                    for ($slashes = 0; $slashes <= 5; $slashes++) {
                        $prefix   = in_array($shape, ['prefix', 'embedded'], true) ? 'prefix' : '';
                        $suffix   = in_array($shape, ['suffix', 'embedded'], true) ? 'suffix' : '';
                        $template = $quote . $prefix . str_repeat('\\', $slashes) . '<field>' . ($shape === 'adjacent' ? '<other>' : '') . $suffix . $quote;
                        $escaped  = $value === '' ? escapeshellarg($value) : cacti_escapeshellarg($value);
                        $argument = \Kadupul\DataInput\Domain\InputStringSubstitution::substitute($template, ['field' => $escaped, 'other' => cacti_escapeshellarg('other')]);
                        $expected = $prefix . str_repeat('\\', $quote === "'" ? $slashes : intdiv($slashes, 2)) . str_replace(["\r", "\n"], '', $value) . ($shape === 'adjacent' ? 'other' : '') . $suffix;
                        $pipes    = [];
                        $process  = proc_open(['/bin/sh', '-c', 'printf %s ' . $argument], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

                        if (!is_resource($process)) {
                            throw new RuntimeException('Cannot start POSIX shell');
                        }

                        $stdout = stream_get_contents($pipes[1]);
                        $stderr = stream_get_contents($pipes[2]);
                        fclose($pipes[1]);
                        fclose($pipes[2]);
                        $status = proc_close($process);
                        $check([$status, $stdout, $stderr], [0, $expected, ''], 'shell ' . $count);
                        $check(file_exists($marker), false, 'execution marker');
                        $count++;
                    }
                }
            }
        }
    } finally {
        if (file_exists($marker)) {
            unlink($marker);
        }

        rmdir($directory);
    }

    return $count;
}
