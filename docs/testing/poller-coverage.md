# Poller integration coverage

The Sonar workflow combines PHPUnit coverage with PCOV observations from a
disposable Docker Compose database, SNMP agent, and application. It exercises
installation, reachable polling and graph generation, unavailable RRDtool,
unreachable devices, a poller that runs twice per cron interval, missing RRD
files, and an unavailable database. Each scenario uses the existing behavior
harness assertions.

The sub-minute scenario (`poller/subminute-loop`) sets a one-minute cron and a
30-second poller interval, so one poll takes at least 30 seconds. It turns
native alerting on and dsstats off, and points the PHP binary setting at a
recording launcher that runs every PHP child unchanged and records each alert
evaluator's exit status and output. A single-run control poll must start one
evaluator, from its end-of-run tasks. The looping poll must report two runs,
sleep once, finish without overrunning its interval, and start two evaluators,
one of them from the tasks between runs. Each evaluator must exit with the
inactive outcome (rate source unavailable). The scenario restores the five
settings it changes and removes the launcher.

Run from the repository root after producing `tests/coverage.php` with PHPUnit's
`--coverage-php` option:

```sh
mise exec python@3.12.12 -- python tests/Support/Behavior/poller_coverage.py --output /tmp/poller-coverage
mise exec python@3.12.12 -- python tests/Support/Behavior/coverage_selftest.py --unit tests/coverage.php --integration /tmp/poller-coverage
mise exec php@8.4 -- php tests/Support/Behavior/merge_poller_coverage.php tests/coverage.php /tmp/poller-coverage tests/coverage.xml
```

The output directory must not already exist. The runner owns the
`kadupul-poller-coverage` Compose project, holds its exclusive lock, and removes
its containers and volumes when finished. Successful observations are published
only after scenario assertions and cleanup succeed.

The merger checks the nine-scenario inventory, source SHA-256 hashes, canonical
paths, PCOV line values, and actual execution of `poller.php`. Generated database
configuration is excluded at capture time. PHPUnit's coverage library merges
observations and writes Clover metrics; the original report is replaced only
after validation and report generation succeed. The failure self-test verifies
that eight kinds of invalid evidence leave an existing report untouched.

This measures the executed scenarios, not every branch or deployment environment.
It does not establish 100% coverage. Unit and integration runtimes on main are PHP 8.4; source hashes must match the checkout used for analysis.
