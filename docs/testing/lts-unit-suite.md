# LTS unit suite

Run every unit file from the repository root:

```sh
mise exec php@8.1.34 python@3.12.12 node@22.22.2 -- python tests/run_unit_suite.py
```

Install the locked test dependencies from `tests/composer.json` first. RRDtool,
GMP, DOM, sockets and the other application PHP extensions enable the runtime
contracts. `RRDTOOL_TEST_BINARY` can select an explicit executable.

The runner discovers every `tests/Unit/**/*Test.php` file. Each runs in a fresh
PHP process because the legacy tests define incompatible global dependency
stubs. No unit file is excluded. A nonzero status, timeout, risky test, warning,
missing report, malformed report or empty report fails the run. Failures do not
prevent later files from being checked. Reports and logs remain in
`artifacts/unit-suite/`; `results.json` records every file and its skip count.

PHP dependency deprecations are suppressed for the Pest 1 runner on PHP 8.4;
application warnings and errors remain failures. Tests explicitly targeting
runtime diagnostics retain their own error handlers. Skips are reported, never
counted as executed assertions: some tests describe helpers absent on LTS, and
others require Linux or optional extensions. CI installs RRDtool and the PHP
extensions and runs the suite on PHP 8.0–8.4.

A passing full suite proves that all discovered tests ran or reported an
explicit skip. It is not a statement of 100% application code coverage. Static
source contracts are also distinct from behavioral tests. New regressions should
exercise the shipped function or rendered output, with external dependencies
stubbed only where necessary. `tests/Helpers/PhpSource.php` supports token-based
function extraction for isolated legacy dependencies; it fails on missing or
incomplete functions and does not use fixed byte windows.

## Shared-process configurations

The other workflows do not use the runner. They name a phpunit configuration,
and Pest loads every file that configuration lists into one process: it rejects
PHPUnit's `processIsolation` with `AttributeNotSupportedYet`, so there is no
per-file isolation to ask for there. `tests/phpunit-boost.xml`,
`phpunit-csrf.xml`, `phpunit-cacti-exec.xml`, `phpunit-spikekill.xml`,
`phpunit-cookie.xml` and `phpunit-boost-mariadb.xml` all work that way.
`phpunit-unit.xml` does not, because the runner always appends one file name to
it.

A file in one of those configurations shares a process with its neighbours, so
three rules apply to it:

- Do not declare a function in the global namespace under a name another file
  in the same configuration declares. The second declaration is a fatal error
  and it ends the whole run. Put stubs of application functions inside a
  per-file `namespace`, which is what the native fixtures do, or give a global
  helper a name unique to the file.
- Do not write a `$GLOBALS` key another file in the configuration writes. Every
  file loads before any test runs, so a load-time write beats a sibling that
  sets the same key from inside its own call and then reads it back.
- Own a value the code under test reads, `$GLOBALS['config']` above all, inside
  the call rather than at load. A fixture that set it at load had its value
  replaced by a sibling, and then read a directory the sibling had removed.

`tests/Unit/Core/Helpers/SuiteIsolationTest.php` enforces all three, reading
each file with `tests/Helpers/SuiteIsolation.php`. That reader tokenises rather
than matching text, because the native fixtures build PHP scripts in heredocs
and every such script declares the application function names at column zero
without declaring anything in this process.

A whole-tree run in one process is not supported and `tests/phpunit.xml`, the
configuration a bare `pest` picks up, says so. Name a file or a directory when
using it. Several global function names are still declared by two files each,
and the shared probes under `tests/Helpers/` declare application function names
in the global namespace, which collide with any file that loads
`lib/functions.php`.
