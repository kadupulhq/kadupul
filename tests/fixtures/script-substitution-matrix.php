<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

/*
 * Based on Cacti 1.2.x tests/fixtures/Core/ScriptSubstitutionMatrix.php
 * (Cacti PR #8238, GHSA-5v3j-wcrr-jxjg). Loaded after lib/functions.php in a
 * child PHP process. Returns the number of checked cases; any mismatch throws.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

function script_substitution_matrix(): int {
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
		['<unknown>', [], ''],
		['"<unknown>"', [], '""'],
		// Tokens without a value are removed before neighbours are checked.
		['"<unknown><field>"', ['field' => "'p'"], "'p'"],
		['$<unknown><field>', ['field' => "'p'"], ''],
		['$<unknown>"<field>"', ['field' => "'p'"], ''],
		['<bad-name>', [], '<bad-name>'],
		['<unterminated', [], '<unterminated'],
		['<0_>', ['0_' => 'ok'], 'ok'],
		// As in 1.2.31, token-shaped text inside a value is removed.
		['<a><b>', ['a' => '<b>', 'b' => 'safe'], 'safe'],
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
		// The shell ends a backtick substitution at the next backtick, even one
		// inside a quoted value, so a field there produces no command.
		['"`printf %s <field>`"', ['field' => "'p'"], ''],
		['`printf %s <field>`', ['field' => "'p'"], ''],
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
		// A field after an unescaped $ would open $'...'.
		['$<field>', ['field' => "'p'"], ''],
		['x$<field>', ['field' => "'p'"], ''],
		['"$<field>"', ['field' => "'p'"], ''],
		['\\\\$<field>', ['field' => "'p'"], ''],
		['\\$<field>', ['field' => "'p'"], '\\$\'p\''],
		// Removing a token without a value must not join its neighbours into
		// syntax the scan never saw.
		['\\<unknown>"<field>"', ['field' => "'p'"], '\\"\'p\'"'],
		['"$<unknown>(<field>)"', ['field' => "'p'"], '"$(\'p\')"'],
		["\$<unknown>'<field>'", ['field' => "'p'"], ''],
		['\\<empty>"<field>"', ['field' => "'p'", 'empty' => ''], '\\"\'p\'"'],
		['"$<empty>(<field>)"', ['field' => "'p'", 'empty' => ''], '"$(\'p\')"'],
		["\$<empty>'<field>'", ['field' => "'p'", 'empty' => ''], ''],
		['<a<unknown>>', ['a' => "'p'"], "'p'"],
		// Inside double quotes $' is literal text.
		['"$\'<field>\'"', ['field' => "'p'"], '"$\'"\'p\'"\'"'],
		// Parameter expansions without fields or backslashes are kept as written.
		['"${HOME:-/tmp}" <field>', ['field' => "'p'"], '"${HOME:-/tmp}" \'p\''],
		['${X#*/} "<field>"', ['field' => "'p'"], '${X#*/} \'p\''],
		["\"\${X:-'}'}\" <field>", ['field' => "'p'"], "\"\${X:-'}'}\" 'p'"],
	];

	foreach ($cases as $index => [$template, $values, $expected]) {
		$check(substitute_script_path($template, $values), $expected, 'parser ' . $index);
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
						$argument = substitute_script_path($template, ['field' => $escaped, 'other' => cacti_escapeshellarg('other')]);
						$expected = $prefix . str_repeat('\\', $quote === "'" ? $slashes : intdiv($slashes, 2)) . preg_replace('/(<[A-Za-z0-9_]+>)+/', '', str_replace(["\r", "\n"], '', $value)) . ($shape === 'adjacent' ? 'other' : '') . $suffix;
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

/**
 * Random templates over shell quoting characters, with field values that try to
 * run a command. Whenever substitute() returns a command, no listed shell may
 * create the marker.
 *
 * @param list<string> $shells
 *
 * @return array{cases: int, commands: int, runs: int}
 */
function script_substitution_property(int $seed, int $cases, array $shells): array {
	$directory = sys_get_temp_dir() . '/kadupul-shell-property-' . bin2hex(random_bytes(8));
	mkdir($directory, 0700);
	$marker   = $directory . '/executed';
	$alphabet = ['\\', '$', '(', ')', '"', "'", '`', ' ', 'a', 'b', '<arg>', '<unknown>'];
	$values   = [
		escapeshellarg('$(touch ' . $marker . ')'),
		escapeshellarg('x ;touch ' . $marker . "; '"),
		escapeshellarg("\\'\$(touch " . $marker . ') #'),
		escapeshellarg('`touch ' . $marker . '`'),
		'',
	];
	$commands = 0;
	$runs     = 0;

	mt_srand($seed, MT_RAND_MT19937);

	try {
		for ($case = 0; $case < $cases; $case++) {
			$template = '';
			$length   = mt_rand(1, 10);

			for ($n = 0; $n < $length; $n++) {
				$template .= $alphabet[mt_rand(0, count($alphabet) - 1)];
			}

			$value   = $values[mt_rand(0, count($values) - 1)];
			$command = substitute_script_path($template, ['arg' => $value]);

			if ($command === '') {
				continue;
			}

			$commands++;

			foreach ($shells as $shell) {
				$pipes   = [];
				$process = proc_open([$shell, '-c', $command], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);

				if (!is_resource($process)) {
					throw new RuntimeException('Cannot start ' . $shell);
				}

				stream_get_contents($pipes[1]);
				stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
				$runs++;

				if (file_exists($marker)) {
					throw new RuntimeException('Field value ran under ' . $shell . ' for case ' . $case . ': ' . var_export($template, true));
				}
			}
		}
	} finally {
		foreach (glob($directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}

		rmdir($directory);
	}

	return ['cases' => $cases, 'commands' => $commands, 'runs' => $runs];
}
