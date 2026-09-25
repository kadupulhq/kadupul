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
`phpunit-cookie.xml`, `phpunit-boost-mariadb.xml` and the root
`phpunit-audit.xml` all work that way.

Two configurations are exempt, and for a stated reason rather than by name.
`tests/phpunit-unit.xml` is exempt because `tests/run_unit_suite.py` appends one
file name to it, and the root `phpunit-csv.xml` because
`.github/workflows/csv-window-coverage.yml` loops over files, naming one each
time. Neither configuration's own suite decides what loads. The root
`phpunit.xml` and `tests/phpunit.xml` declare the whole tree and nothing runs
them; a whole-tree run in one process does not work, and no workflow may start
using them without first dealing with that.

A file in a shared configuration shares a process with its neighbours, so:

- Do not declare a function in the global namespace under a name another file
  in the same configuration declares. An unguarded second declaration is a
  fatal error and it ends the whole run. A `function_exists()` guard stops the
  fatal error but not the problem: only the first body runs, for every file in
  the process, so a shared stub has to be the same code in each file. Prefer a
  per-file `namespace`, which is what the native fixtures do, or a global
  helper with a name unique to the file. A function declared inside an `if` is
  still a global function.
- Do not mutate a `$GLOBALS` key another file in the configuration mutates.
  Every file loads before any test runs, so a load-time write beats a sibling
  that sets the same key from inside its own call and then reads it back.
  Assignment, `++`, `--`, `unset()` and a by-reference bind all count, as does
  a name declared with the `global` keyword, which reaches the same slot
  without naming `$GLOBALS`. A write inside a top-level `if` is a load-time
  write; one inside a function, a closure or a method is not.
- Own a value the code under test reads, `$GLOBALS['config']` above all, inside
  the call rather than at load. A fixture that set it at load had its value
  replaced by a sibling, and then read a directory the sibling had removed.

`tests/Unit/Core/Helpers/SuiteIsolationTest.php` enforces all of that, reading
each file with `tests/Helpers/SuiteIsolation.php`. It also asserts that every
phpunit configuration in the repository is either checked or listed as exempt
with its reason, so a new one cannot appear unexamined. The reader tokenises
rather than matching text, because the native fixtures build PHP scripts in
heredocs and every such script declares the application function names at
column zero without declaring anything in this process; a text scan reported 43
collisions in `phpunit-spikekill.xml`, which runs green.

Two violations it found on the way in: `cacti_log()` was declared in two
spikekill files with different bodies writing to different collection globals,
so whichever loaded second read an empty log, and `__()` was declared twice with
equivalent but differing bodies.

`tests/phpunit.xml`, the configuration a bare `pest` picks up under `tests/`,
bootstraps the source-scan helpers rather than `../include/global.php`, which
needs a database. Name a file or a directory when using it.
