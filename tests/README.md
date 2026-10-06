# Test suites on main

Use `mise` with the versions in `mise.toml`. Install the locked application
dependencies with `mise exec -- composer install` and the independent test
dependencies with `mise exec -- composer install --working-dir=tests`.
The application uses PHPUnit 10; the Pest 4 sandbox uses PHPUnit 12. Keep
their vendor trees separate. Do not run a generic `phpunit` command: the
default configurations have different bootstrap and database requirements.
Use `memory_limit=2G` in the local PHP coverage configuration for the whole-tree
report; completing selected tests alone does not establish that the report was written.

`make test` retains its existing behavioral characterization meaning. There
is no aggregate command that establishes that all CI checks passed.

| Command | Discovery and prerequisites |
| --- | --- |
| `make test-characterization` | Ordered container scenarios and committed goldens; see the [characterization guide](../docs/testing/README.md). Requires Docker Compose. |
| `make test-symfony` | Application PHPUnit 10, `phpunit-symfony.xml`, `tests/Symfony`. Also available through `composer test`. |
| `make test-legacy` | Pest 4, `tests/phpunit-spikekill.xml`: its explicit legacy regression suites, beyond spike removal. CI exercises privileged and unprivileged variants. |
| `make test-database` | Pest 4, `tests/phpunit-database.xml`: the explicit database contract files. Prepare a disposable database and set the `BOOST_DB_*` connection variables as in the CI database matrix; these tests perform writes. |
| `make test-unit-coverage` | Pest 4, `tests/phpunit-coverage.xml`: the configured Unit, HandOff, handoff and mutation contribution, with existing exclusions preserved. Requires PCOV or Xdebug; writes its configured Clover report. |
| `make test-javascript` | Node's native test runner, all `tests/Unit/*.test.mjs`. Install root dependencies with `mise exec -- npm ci` and build shipped assets with `mise exec -- npm run build` first. Some contracts also execute PHP; run with the locked PHP runtime and required extensions. This includes more contracts than the root `npm test` selection. |
| `make test-themes` | The existing `test:themes` script in `tests/e2e/package.json`, explicitly using `playwright.config.js` and its local PHP server. Install locked browser dependencies with `mise exec -- npm ci --prefix tests/e2e` and the required Playwright browser/system dependencies. |
| `make test-runner-contracts` | Python subprocess tests for suite dispatch, argument preservation, unavailable dependencies, empty JavaScript discovery and runner failure propagation. |

Pass native runner arguments directly when narrowing or inspecting discovery:

```sh
./tests/bin/run symfony --list-tests
./tests/bin/run legacy --list-tests
./tests/bin/run database --list-tests
./tests/bin/run unit-coverage --list-tests
./tests/bin/run javascript --test-name-pattern='specific behavior'
./tests/bin/run themes --list
```

PHP entrypoints fail on empty test suites. Missing vendor entrypoints and
empty JavaScript discovery fail before launching the runtime. Filters can
narrow the run; a successful filtered run proves only the selected cases.

Installed HTTP/CSP browser tests intentionally use the separate
`tests/e2e/playwright.config.ts` configuration and `E2E_BASE_URL`; they are
not part of the local theme command. Follow their existing
[browser instructions](e2e/README.md) and CI provisioning.

CI also runs installed application smoke tests, database engine matrices,
security scripts, poller/HTTP coverage producers, coverage receipt validation
and negative self-tests, catalogs, dependency/build checks and other native
jobs. The unit coverage command alone does not establish complete Sonar
coverage or its quality gate. Preserve the native jobs and their provenance
registrations when changing test paths; inspect `.github/workflows` for the
current authoritative job selections.

Name new files and cases after the behavior or invariant they exercise.
Use `*SourceContractTest.php` for structural/source checks, `*Test.php` for
native PHP behaviors, and `*.test.mjs` for Node contracts. Keep upstream
issue/advisory identifiers in comments as provenance. A source assertion
that a function signature exists does not prove that the file parses or that
the function executes.

The placeholder regression file is `Unit/InputStringPlaceholderRegressionTest.php`; its existing coverage exclusion remains while #805 tracks its fixture dependency. `integration/DataInputImportValidationContractTest.php` checks source ordering and native shared-validator contracts. The registered Symfony HTTP/worker scenarios provide installed data-input save/persistence evidence. Issue references and inherited attribution remain in both files.
