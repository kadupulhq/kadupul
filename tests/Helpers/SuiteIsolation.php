<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Pest runs every file of a suite in one process, and rejects PHPUnit's
 * processIsolation outright, so the only isolation the repository has is
 * tests/run_unit_suite.py naming one file per process. Anything listed in a
 * phpunit configuration therefore shares a process with its neighbours, and
 * two files that declare the same global function, or that write $GLOBALS
 * while the file loads, break each other.
 *
 * These readers answer what a file declares at the top level. They tokenise
 * rather than match text, because the native fixtures build PHP scripts in
 * heredocs and pass them to a subprocess, and every such script declares the
 * application function names at column zero without declaring anything in this
 * process.
 */

/**
 * The test files a phpunit configuration puts in one process, as paths
 * relative to the tests directory.
 *
 * @param string $config path to a phpunit xml file
 *
 * @return array<int,string>
 */
function suite_isolation_files($config) {
	/* DOM rather than SimpleXML, which is a separate extension the workflows
	 * do not install */
	$document = new DOMDocument();

	if (!$document->load($config)) {
		return array();
	}

	$xpath = new DOMXPath($document);
	$base  = dirname(realpath($config));
	$files = array();

	/* only inside testsuites: phpunit-audit.xml also names lib/audit.php under
	 * <source><include>, which is coverage scope rather than a test file */
	foreach ($xpath->query('//testsuites//file') as $entry) {
		$path = $base . '/' . ltrim(preg_replace('#^\./#', '', trim($entry->textContent)), '/');

		if (is_file($path)) {
			$files[] = $path;
		}
	}

	foreach ($xpath->query('//testsuites//directory') as $entry) {
		$suffix = $entry->hasAttribute('suffix') ? $entry->getAttribute('suffix') : '.php';
		$path   = $base . '/' . ltrim(preg_replace('#^\./#', '', trim($entry->textContent)), '/');

		if (!is_dir($path)) {
			continue;
		}

		$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

		foreach ($walk as $found) {
			$name = $found->getPathname();

			if (substr($name, -strlen($suffix)) === $suffix) {
				$files[] = $name;
			}
		}
	}

	sort($files);

	return array_values(array_unique($files));
}

/**
 * PHP 8.1 splits the ampersand into two tokens by what follows it, and neither
 * says whether this one binds or masks. The caller decides from what precedes.
 *
 * @param int|string $type
 *
 * @return bool
 */
function suite_isolation_is_ampersand($type) {
	foreach (array('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG', 'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') as $name) {
		if (defined($name) && $type === constant($name)) {
			return true;
		}
	}

	return false;
}

/**
 * What a file declares in the process that loads it.
 *
 * 'namespace' is the first namespace declared, or '' for the global one.
 * 'functions' holds the names of the functions declared at the top level of
 * the global namespace, which are the only ones that can collide with another
 * file. 'globals' maps each key written to $GLOBALS while the file loads to
 * the line that wrote it; those writes are what reaches another file's tests,
 * so two files in one suite writing the same key overwrite each other before a
 * single test has run. A key that is not a plain string is recorded as '?'.
 *
 * 'writes' holds every key the file assigns to $GLOBALS anywhere, at load or
 * from inside a function, which is what makes two files contend for one key
 * even when only one of them writes it at load.
 *
 * 'braced' says the file declares a namespace with a brace. The reader takes
 * the first namespace for the whole file, so a braced one may be followed by a
 * global block whose declarations it would not report. No file in the suite
 * does that, and the guard keeps it that way.
 *
 * @param string $path
 *
 * @return array{namespace:string,braced:bool,functions:array<int,string>,globals:array<string,int>,writes:array<int,string>}
 */
function suite_isolation_declarations($path) {
	$tokens = token_get_all(file_get_contents($path));
	$result = array('namespace' => '', 'braced' => false, 'functions' => array(), 'globals' => array(), 'writes' => array());

	$depth      = 0;
	$class_body = array();
	$namespace  = '';
	$count      = count($tokens);

	for ($i = 0; $i < $count; $i++) {
		$token = $tokens[$i];

		if (!is_array($token)) {
			if ($token === '{') {
				$depth++;
			} elseif ($token === '}') {
				if (end($class_body) === $depth) {
					array_pop($class_body);
				}

				$depth--;
			}

			continue;
		}

		/**
		 * A method is not a global function, but a function declared inside an
		 * if or inside another function is: PHP declares it when that code
		 * runs, and the usual shape is a function_exists() guard, which is
		 * exactly the collision this guard exists to find. So track class-like
		 * bodies rather than brace depth.
		 */
		if (in_array($token[0], array(T_CLASS, T_INTERFACE, T_TRAIT), true) || (defined('T_ENUM') && $token[0] === T_ENUM)) {
			for ($j = $i + 1; $j < $count; $j++) {
				if ($tokens[$j] === '{') {
					$class_body[] = $depth + 1;

					break;
				}

				if ($tokens[$j] === ';' || $tokens[$j] === '(') {
					break;
				}
			}

			continue;
		}

		/**
		 * "{$x}" and "${x}" open a brace that the tokeniser reports as its own
		 * token while the closing one is a bare '}'. Counting only the bare
		 * form drove the depth negative at the first interpolated string, and
		 * every later top-level declaration then read as nested and went
		 * unreported. One file in the Boost suite interpolates.
		 */
		if (in_array($token[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true)) {
			$depth++;

			continue;
		}

		if ($token[0] === T_NAMESPACE && $namespace === '') {
			for ($j = $i + 1; $j < $count; $j++) {
				if (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_STRING, T_NAME_QUALIFIED), true)) {
					$namespace = $tokens[$j][1];

					continue;
				}

				if ($tokens[$j] === '{') {
					/* a braced namespace can be followed by a global one, and
					 * the first-wins rule below would then hide it */
					$result['braced'] = true;

					break;
				}

				if ($tokens[$j] === ';') {
					break;
				}
			}

			continue;
		}

		/* a closure or an arrow function has no name, so nothing to collide */
		if ($token[0] === T_FUNCTION && $class_body === array() && $namespace === '') {
			for ($j = $i + 1; $j < $count; $j++) {
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
					$result['functions'][] = $tokens[$j][1];

					break;
				}

				if ($tokens[$j] === '(' || $tokens[$j] === ';') {
					break;
				}
			}

			continue;
		}

		if ($token[0] === T_VARIABLE && $token[1] === '$GLOBALS') {
			$key = '?';

			/**
			 * A read leaves nothing behind, so only a mutation counts. An
			 * assignment is the common one, but ++, --, unset() and a
			 * by-reference bind all change the value the next file sees, and
			 * a guard that recognised only '=' could be stepped around.
			 */
			$mutates = false;

			/* unset($GLOBALS['k']) and &$GLOBALS['k'] put the operator first */
			for ($back = $i - 1; $back >= 0; $back--) {
				if (is_array($tokens[$back]) && in_array($tokens[$back][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
					continue;
				}

				if (is_array($tokens[$back]) && in_array($tokens[$back][0], array(T_INC, T_DEC), true)) {
					$mutates = true;
				} elseif ($tokens[$back] === '&' || (is_array($tokens[$back]) && suite_isolation_is_ampersand($tokens[$back][0]))) {
					/* a bind, = &$GLOBALS[...], rather than a bitwise and */
					for ($outer = $back - 1; $outer >= 0; $outer--) {
						if (is_array($tokens[$outer]) && $tokens[$outer][0] === T_WHITESPACE) {
							continue;
						}

						if ($tokens[$outer] === '=') {
							$mutates = true;
						}

						break;
					}
				} elseif (is_array($tokens[$back]) && $tokens[$back][0] === T_UNSET) {
					$mutates = true;
				} elseif ($tokens[$back] === '(') {
					/* the token before the paren decides; unset( is the case */
					for ($outer = $back - 1; $outer >= 0; $outer--) {
						if (is_array($tokens[$outer]) && $tokens[$outer][0] === T_WHITESPACE) {
							continue;
						}

						if (is_array($tokens[$outer]) && $tokens[$outer][0] === T_UNSET) {
							$mutates = true;
						}

						break;
					}
				}

				break;
			}

			for ($j = $i + 1; $j < $count; $j++) {
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING && $key === '?') {
					$key = trim($tokens[$j][1], "'\"");

					continue;
				}

				if ($tokens[$j] === '=' || (is_array($tokens[$j]) && in_array($tokens[$j][0], array(T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_COALESCE_EQUAL, T_INC, T_DEC), true))) {
					$mutates = true;

					break;
				}

				if ($tokens[$j] === ';' || $tokens[$j] === ',' || $tokens[$j] === ')') {
					break;
				}
			}

			if ($mutates) {
				$result['writes'][] = $key;

				if ($depth === 0) {
					$result['globals'][$key] = $token[2];
				}
			}
		}
	}

	$result['namespace'] = $namespace;
	$result['writes']    = array_values(array_unique($result['writes']));

	return $result;
}
