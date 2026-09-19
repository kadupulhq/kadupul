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
