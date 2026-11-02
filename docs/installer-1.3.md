# Installer workflow for 1.3

The web installer and `kadupul:install` share a Symfony application workflow.
The existing native installer remains responsible for schema migrations,
template imports, plugin hooks and collector synchronization. This change does
not introduce a new schema version or change the supported dependency stack.
Use PHP 8.4 with the locked Symfony 7.4 dependencies.

## Prepare and review

Configure the database through the existing `include/config.php` deployment
process and import the shipped fresh-install SQL when creating a new database.
Provide the same strong `APP_SECRET` (at least 32 bytes) to web and CLI processes
through the deployment's secret store. Keep it outside the document root. The
installer uses it to authenticate review fingerprints without exposing an
offline fingerprint of stored SNMP credentials. Rotating it invalidates saved
reviews; review the configuration again before execution.

Open `install/install.php` and authenticate with Installation/Upgrades
permission. Authentication must be configured and the account must remain
eligible; an earlier browser session does not authorize installation after
authentication is disabled. Check requirements, select the collection profile, accept the
license, and save the configuration before reviewing the execution plan.
Discovery defaults to disabled and requires separate confirmation when enabled.
Blank SNMP secret fields retain existing values; secrets are never redisplayed.

The equivalent CLI entry points are:

```sh
php bin/console kadupul:install
php bin/console kadupul:install --action=status --output=json
php bin/console kadupul:install --action=configure --accept-eula --collection-profile=1 --automationmode=0
php bin/console kadupul:install --install --accept-eula --collection-profile=1 --automationmode=0
```

The first command only inspects requirements. `--install` authorizes execution
of the supplied configuration; discovery additionally requires
`--confirm-discovery`. Use `--as` for the existing console operator selection
policy. CLI operators require the existing console and upgrade permissions.
Browser operators retain the native installer permission rules.
An operator whose account requires a password change must complete the native
password-change flow before using the CLI. Fresh-install automation must
explicitly provision its eligible administrator before running installation.
`cli/install_cacti.php` translates legacy flags into this command, including
legacy `--profile` into `--collection-profile`. Explicit flags override values
from `--configuration` JSON or legacy `--ini` files. Configuration files are
limited to 64 KiB and must be kept outside the document root when containing
credentials. Prefer protected files over secret command-line arguments.
Omitted SNMP fields retain their stored values. An explicitly empty secret in
CLI configuration clears that value; blank browser password fields retain it
because those fields never redisplay stored credentials.

## Execution and recovery

GET requests and status polling never start, retry or finalize installation.
Configuration is saved in a transaction on the verified native mutation
connection. Settings and preference tables must be persistent InnoDB tables.
A database advisory lock excludes competing operations through the new
workflow. It does not serialize arbitrary legacy tools or outside writers.

The worker validates the reserved operator and reviewed configuration again
before native execution. A waiting receipt means reservation, not confirmed
process startup. A failed or partial result is not installation success. An explicit
forced CLI repeat returns without writes only when the current schema and a
retained successful receipt both confirm completion. A current schema alone
does not establish that finishing tasks succeeded.
Collector synchronization failures remain visible after the primary schema is
installed. A subsequent explicit finishing-task retry does not replay schema
migrations. Successful finalization runs the existing integrated-plugin cleanup
and removes native transient installation flags; diagnostic logs remain.

Before retrying, preserve `log/cacti.log`, inspect the database and confirm the
previous worker and its children have stopped. Recover a stale reservation
through the web confirmation or the exact attempt reported by status:

```sh
php bin/console kadupul:install --action=recover --attempt=12 --force
php bin/console kadupul:install --install --force
```

Recovery marks that attempt stopped without terminating processes, changing
saved selections or starting another worker. The second command explicitly
retries the saved plan. Do not use it until the cause is corrected. Plugin
uninstall callbacks can have external effects; interruption during cleanup
requires operator inspection before authorizing another attempt.

## Deployment and rollback

Back up the database, RRD files, configuration and key material before upgrade.
Drain native installer workers and other upgrade tools before replacing the
application files, then clear the Symfony cache for the deployed environment
with `php bin/console cache:clear`. Stale browser clients using `step_json.php` receive a
controlled conflict and must reload the installer. The historical background
entry remains for draining an already launched legacy job; do not run old and
new write paths concurrently.

Application rollback requires stopping installer workers first and restoring
matching files. Reverting code does not undo committed schema migrations,
template imports or external plugin effects. Restore tested backups when data
restoration is required. A matching schema version alone does not prove poller
scheduling, collector connectivity, RRD access or collection freshness; verify
these operationally before enabling discovery.

## Verification scope

Focused Symfony tests exercise request validation, CSRF refresh, explicit
execution/recovery consent, CLI argument handoff and status observation. Native
database tests use actual shipped settings/version DDL on a disposable MySQL
server to exercise locking, temporary-table rejection, caller-owned transaction
preservation, exact receipt readback and schema/receipt consistency.
The native browser harness also exercises fresh installation through actual
login, rejected and automatically refreshed CSRF, explicit execution consent,
the background worker, strict CSP, French content and French validation errors.
It runs in the existing disposable Docker stack with
`CACTI_E2E_INSTALLER_BROWSER=1 tests/e2e/docker/run.sh` and gates the CLI Docker
integration result in CI. It requires the locked E2E runner and Chromium.
These checks do not establish complete browser accessibility, remote collector
recovery or production upgrade compatibility. Those remain release gates until
exercised with representative environments.
