<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The suite has two isolation modes and they do not agree. tests/run_unit_suite.py
 * runs each file in its own process, which is what the LTS unit workflow uses.
 * Every other workflow names a phpunit configuration, and Pest runs all of that
 * configuration's files in one process: it rejects PHPUnit's processIsolation
 * with AttributeNotSupportedYet, so there is no per-file isolation there to ask
 * for.
 *
 * A file added to one of those configurations therefore shares a process with
 * its neighbours, and the two ways that has broken this suite are a second
 * declaration of the same global function, which is a fatal error, and a
 * $GLOBALS write made while the file loads. The second is the quieter one: all
 * files load before any test runs, so the last writer wins and a fixture reads
 * a sibling's value. Both were found by a failure rather than by a rule, which
 * is what this file changes.
 *
 * It does not try to make the whole Unit tree run in one process. Several
 * global function names are still declared by two files each, and the shared
 * probes under Helpers/ declare application function names in the global
 * namespace, which collide with any file that loads lib/functions.php. That is
 * a migration, not a guard, and the per-file runner is the stronger isolation
 * regardless.
 *
 * Nor does it read the Helpers/ files a suite requires. Two of those could
 * collide with each other in the same way, and nothing here would say so.
 */

namespace SuiteIsolationTest;

require_once dirname(__DIR__, 3) . '/Helpers/SuiteIsolation.php';

/**
 * Every phpunit configuration in the repository, under tests/ and at the root,
 * where the workflows keep three of them.
 *
 * @return array<int,string>
 */
function every_config() : array {
	$tests = dirname(__DIR__, 3);
	$root  = dirname($tests);

	return array_values(array_unique(array_merge(
		glob($tests . '/phpunit*.xml'),
		glob($root . '/phpunit*.xml')
	)));
}

/**
 * The configurations whose files do not share a process, with the reason. Each
 * reason is asserted below, so an entry cannot become untrue quietly.
 *
 * @return array<string,string>
 */
function exempt_configs() : array {
	return array(
		/* run_unit_suite.py appends one file name to it */
		'tests/phpunit-unit.xml' => 'tests/run_unit_suite.py',
		/* the csv workflow loops, naming one file per run */
		'phpunit-csv.xml'        => '.github/workflows/csv-window-coverage.yml',
		/* nothing runs these two; they declare the whole tree, which cannot
		 * load in one process */
		'tests/phpunit.xml'      => '',
		'phpunit.xml'            => '',
	);
}

/**
 * Every configuration whose files share one process.
 *
 * @return array<int,string>
 */
function shared_configs() : array {
	$root   = dirname(dirname(__DIR__, 3)) . '/';
	$exempt = exempt_configs();

	return array_values(array_filter(every_config(), static function ($path) use ($root, $exempt) {
		return !isset($exempt[str_replace($root, '', $path)]);
	}));
}

it('finds the shared configurations it means to check', function () {
	$configs = shared_configs();

	expect($configs)->not->toBeEmpty();

	foreach ($configs as $config) {
		expect(\suite_isolation_files($config))->not->toBeEmpty();
	}
});

it('declares no global function twice inside one process', function () {
	require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

	foreach (shared_configs() as $config) {
		$declared = array();

		foreach (\suite_isolation_files($config) as $path) {
			foreach (\suite_isolation_declarations($path)['functions'] as $name) {
				$declared[strtolower($name)][$path] = $name;
			}
		}

		$wrong = array();

		foreach ($declared as $files) {
			if (count($files) < 2) {
				continue;
			}

			$name   = reset($files);
			$bodies = array();

			foreach (array_keys($files) as $path) {
				$source = file_get_contents($path);

				/**
				 * Two declarations of one name are a fatal error unless both
				 * are behind a function_exists() guard. Even then only the
				 * first body runs, for every file in the process, so the
				 * bodies have to be the same code or one file is quietly
				 * testing against another's stub.
				 */
				if (strpos($source, "function_exists('" . $name . "')") === false) {
					$wrong[] = basename($config) . ': ' . $name . '() declared by ' . basename($path) . ' without a function_exists guard';
				}

				$bodies[preg_replace('/\s+/', ' ', \test_php_function_source($source, $name))][] = basename($path);
			}

			if (count($bodies) > 1) {
				$wrong[] = basename($config) . ': ' . $name . '() declared with differing bodies by ' . implode(', ', array_merge(...array_values($bodies)));
			}
		}

		expect($wrong)->toBe(array());
	}
});

it('writes no shared $GLOBALS key while two files load', function () {
	foreach (shared_configs() as $config) {
		$owner  = array();
		$second = array();

		foreach (\suite_isolation_files($config) as $path) {
			foreach (array_keys(\suite_isolation_declarations($path)['globals']) as $key) {
				if (isset($owner[$key])) {
					$second[] = basename($config) . ": \$GLOBALS['$key'] written at load by " . basename($path) . ' and ' . basename($owner[$key]);
				}

				$owner[$key] = $path;
			}
		}

		expect($second)->toBe(array());
	}
});

it('does not write at load a key another file in the suite also owns', function () {
	foreach (shared_configs() as $config) {
		$declared = array();

		foreach (\suite_isolation_files($config) as $path) {
			$declared[$path] = \suite_isolation_declarations($path);
		}

		$contended = array();

		foreach ($declared as $path => $one) {
			foreach (array_keys($one['globals']) as $key) {
				foreach ($declared as $other => $two) {
					// A load-time write happens before any test runs, so it
					// beats a sibling that writes the same key from inside its
					// own call and then reads it back. That is how a fixture
					// came to read another file's rra_path, and the directory
					// it named had already been removed.
					if ($other !== $path && in_array($key, $two['writes'], true)) {
						$contended[] = basename($config) . ": \$GLOBALS['$key'] written at load by " . basename($path) . ' and also by ' . basename($other);
					}
				}
			}
		}

		expect($contended)->toBe(array());
	}
});

it('reads a declaration rather than matching text for it', function () {
	// The reader has to tokenise: the native fixtures build a PHP script in a
	// heredoc and run it in a subprocess, and such a script declares the
	// application function names at column zero without declaring anything
	// here. A text match reports all of them.
	$fixture = <<<'SOURCE'
<?php
function fixture_top_level() {
	$GLOBALS['inside_a_function'] = 1;

	return 1;
}

$name = 'x';

/* both interpolation forms open a brace the tokeniser reports separately from
 * the bare one that closes it, so a declaration after them must still count */
$interpolated = "a {$name} b";
$older        = "c ${name} d";

function fixture_after_interpolation() { return 2; }

$GLOBALS['written_at_load'] = 3;

$closure = function () { return 4; };

$script = <<<'INNER'
function read_config_option($name) { return ''; }
$GLOBALS['config'] = array();
INNER;

class FixtureHolder {
	public function method() { return 5; }
}
SOURCE;

	$path = sys_get_temp_dir() . '/suite-isolation-' . getmypid() . '-' . mt_rand() . '.php';

	file_put_contents($path, $fixture);

	try {
		$declared = \suite_isolation_declarations($path);

		expect($declared['namespace'])->toBe('');
		expect($declared['braced'])->toBeFalse();
		expect($declared['functions'])->toBe(array('fixture_top_level', 'fixture_after_interpolation'));
		expect(array_keys($declared['globals']))->toBe(array('written_at_load'));

		// The write inside the function is contention but not a load-time one.
		expect($declared['writes'])->toBe(array('inside_a_function', 'written_at_load'));
	} finally {
		unlink($path);
	}
});

it('reports nothing global from a namespaced file', function () {
	$fixture = "<?php\nnamespace Fixture;\nfunction read_config_option(\$name) { return ''; }\n\$GLOBALS['config'] = array();\n";

	$path = sys_get_temp_dir() . '/suite-isolation-ns-' . getmypid() . '-' . mt_rand() . '.php';

	file_put_contents($path, $fixture);

	try {
		$declared = \suite_isolation_declarations($path);

		// A namespaced declaration cannot collide, which is why the fixtures
		// that stub application functions are written that way. The $GLOBALS
		// write is still reported, because $GLOBALS has no namespace.
		expect($declared['namespace'])->toBe('Fixture');
		expect($declared['functions'])->toBe(array());
		expect(array_keys($declared['globals']))->toBe(array('config'));
	} finally {
		unlink($path);
	}
});

it('notices a braced namespace, which it cannot read past', function () {
	$fixture = "<?php\nnamespace Fixture {\n\tfunction inner() { return 1; }\n}\n\nnamespace {\n\tfunction global_after_braced() { return 2; }\n}\n";

	$path = sys_get_temp_dir() . '/suite-isolation-braced-' . getmypid() . '-' . mt_rand() . '.php';

	file_put_contents($path, $fixture);

	try {
		$declared = \suite_isolation_declarations($path);

		// The reader takes the first namespace for the whole file, so it does
		// not see global_after_braced(). It says so rather than reporting a
		// clean file, and the suites are asserted free of the construct below.
		expect($declared['braced'])->toBeTrue();
		expect($declared['functions'])->toBe(array());
	} finally {
		unlink($path);
	}
});

it('has no braced namespace in any shared suite', function () {
	foreach (shared_configs() as $config) {
		$braced = array();

		foreach (\suite_isolation_files($config) as $path) {
			if (\suite_isolation_declarations($path)['braced']) {
				$braced[] = basename($config) . ': ' . basename($path);
			}
		}

		expect($braced)->toBe(array());
	}
});

it('resolves both a file entry and a directory entry', function () {
	$boost = dirname(__DIR__, 3) . '/phpunit-boost.xml';
	$files = \suite_isolation_files($boost);

	expect($files)->not->toBeEmpty();

	$names = array_map('basename', $files);

	// One named file and one the directory entry has to find.
	expect($names)->toContain('BoostDataHandOffTest.php');
	expect($names)->toContain('BoostSingleDsLockTest.php');

	foreach ($files as $path) {
		expect(is_file($path))->toBeTrue();
	}
});

it('accounts for every configuration in the repository', function () {
	$root   = dirname(dirname(__DIR__, 3)) . '/';
	$exempt = exempt_configs();
	$shared = array_map(static function ($path) use ($root) {
		return str_replace($root, '', $path);
	}, shared_configs());

	// A new configuration is checked unless it is listed as exempt, so one
	// cannot be added and go unexamined.
	foreach (every_config() as $path) {
		$name = str_replace($root, '', $path);

		expect(isset($exempt[$name]) || in_array($name, $shared, true))->toBeTrue();
	}

	// The ones the workflows actually run in one process.
	expect($shared)->toContain('tests/phpunit-boost.xml');
	expect($shared)->toContain('tests/phpunit-csrf.xml');
	expect($shared)->toContain('tests/phpunit-spikekill.xml');
	expect($shared)->toContain('tests/phpunit-cacti-exec.xml');
	expect($shared)->toContain('phpunit-audit.xml');
});

it('holds each exemption to the reason given for it', function () {
	$root = dirname(dirname(__DIR__, 3)) . '/';

	foreach (exempt_configs() as $name => $caller) {
		if ($caller === '') {
			// Nothing may start running a whole-tree configuration without
			// also dealing with the collisions that stop it loading.
			foreach (glob($root . '.github/workflows/*.yml') as $workflow) {
				expect(file_get_contents($workflow))->not->toContain('=' . $name);
			}

			continue;
		}

		$source = file_get_contents($root . $caller);

		expect($source)->not->toBeFalse();

		// The caller names the configuration and a test file in the same
		// command, so the configuration's own suite never decides what loads.
		expect($source)->toContain(basename($name));
		expect(preg_match('/Test\.php/', $source))->toBe(1);
	}
});

it('reads only the test files a configuration lists', function () {
	$root  = dirname(dirname(__DIR__, 3)) . '/';
	$files = \suite_isolation_files($root . 'phpunit-audit.xml');

	expect($files)->not->toBeEmpty();

	// phpunit-audit.xml names lib/audit.php under <source><include>, which is
	// coverage scope. Reading it as a suite file would scan the application.
	foreach ($files as $path) {
		expect(basename($path))->toEndWith('Test.php');
	}
});

it('reports a function declared inside a conditional', function () {
	// The usual shape is a function_exists() guard, and PHP declares the
	// function globally when the branch runs, so it collides like any other.
	$fixture = "<?php\nif (!function_exists('read_config_option')) {\n\tfunction read_config_option(\$n) { return ''; }\n}\n\nclass Holder { public function method() { return 1; } }\n";

	$path = sys_get_temp_dir() . '/suite-isolation-cond-' . getmypid() . '-' . mt_rand() . '.php';

	file_put_contents($path, $fixture);

	try {
		$declared = \suite_isolation_declarations($path);

		// The guarded function counts; the method does not.
		expect($declared['functions'])->toBe(array('read_config_option'));
	} finally {
		unlink($path);
	}
});

it('reports the mutations that are not assignments', function () {
	$fixture = "<?php\n\$GLOBALS['counter'] = 0;\n\$GLOBALS['counter']++;\n++\$GLOBALS['other'];\nunset(\$GLOBALS['gone']);\n\$bound = &\$GLOBALS['bound'];\n\$mask = 1 & \$GLOBALS['read_only'];\n\$look = \$GLOBALS['plain_read'];\n";

	$path = sys_get_temp_dir() . '/suite-isolation-mut-' . getmypid() . '-' . mt_rand() . '.php';

	file_put_contents($path, $fixture);

	try {
		$declared = \suite_isolation_declarations($path);

		// ++, --, unset() and a by-reference bind all change what the next file
		// sees; a bitwise and and a plain read do not.
		expect(array_keys($declared['globals']))->toBe(array('counter', 'other', 'gone', 'bound'));
	} finally {
		unlink($path);
	}
});

it('keeps the per file runner the only isolating entry point', function () {
	$runner = file_get_contents(dirname(__DIR__, 3) . '/run_unit_suite.py');

	expect($runner)->not->toBeFalse();

	// Pest rejects processIsolation, so the runner naming one file per process
	// is the whole of the isolation this repository has.
	expect($runner)->toContain('--configuration=phpunit-unit.xml');
	expect($runner)->toContain('relative]');

	// And no configuration under tests/ may bootstrap the application, which
	// needs a database a unit run does not have.
	foreach (glob(dirname(__DIR__, 3) . '/phpunit*.xml') as $config) {
		$document = new \DOMDocument();

		expect($document->load($config))->toBeTrue();
		expect($document->documentElement->getAttribute('bootstrap'))->not->toContain('global.php');
	}
});
