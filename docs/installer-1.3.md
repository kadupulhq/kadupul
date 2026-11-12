# Installer workflow

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
For environment-backed secret references, local `--validate` checks and stable
automation exit codes, see [unattended configuration](installer-unattended.md).

The common web form keeps optional discovery, collector information, paths and
template selections in keyboard-operable disclosure groups. Existing selections
are still submitted. The review lists the version change, collection profile,
interval, selected templates, discovery scope and backup prerequisites.

Preflight additionally checks the worker files and dependencies, temporary and
import destinations, web background-launch functions and transaction ownership
on the actual database connection. These are observations, not a test execution
of the worker. Effective database privileges remain an explicit nonblocking DBA
check: roles and object-level migration privileges cannot be proved by these
read-only checks. A ready plan does not guarantee that every later DDL or plugin
operation will succeed.

## Execution and recovery

### Compare the web and installer runtimes

Use the explicit runtime comparison form after signing in and acknowledging the
read-only check. It starts a short-lived probe with the configured PHP executable,
without loading the native installer, opening a database or reserving an attempt.
GET requests and ordinary requirement checks never start this probe.

The report compares PHP support, required extensions, effective process identity
and directory observations. Supported patch-version or intentional account
differences are advisory. Missing required read/traversal access or extensions
requires attention. Equal permission observations alone do not establish that
either process can complete future writes.

A private, random challenge checks that the spawned process inherits the same
strong application secret. The web secret is not copied into the child to make
the check pass. Snapshots, challenges, proofs, account IDs and directory paths
are not rendered or included in the diagnostic download. This compares the
installer process spawned by the web service, not independently managed cron,
poller or collector services. Check those service accounts separately.
The probe disables PHP auto-prepend/append hooks and does not bootstrap the
application. Its observations do not establish the effects of those hooks or
plugin code during a normal worker startup.

### Opt in to operational evidence

```sh
php bin/console kadupul:install:verify --acknowledge-read-only --output=json
```

The eligible installation operator authorizes this bounded assessment explicitly.
It reports the configured local poller's saved start, completed-cycle bookkeeping
and native status. Optional repeated `--graph-id` arguments select up to twenty
existing graphs and additionally require graph management and native per-graph
permission. Only existing stored paths are read; missing paths are never repaired.
At most twenty distinct contained local RRD files are inspected within a shared
time budget. The command does not start collection, contact devices, flush a
daemon or render graphs. Remote storage remains unassessed.
The assessment uses the local collector connection even when its normal mode is
online; the configured primary is not contacted. `sampled_at` records the start
of the assessment. Individual observations occur during the bounded interval and
do not constitute an atomic database/filesystem snapshot.

Local on-disk RRD timestamps can lag buffered daemon updates. A stale timestamp
requires investigation, not a conclusion that a device failed. Inspect the reason
codes and normal collector/daemon operation before taking corrective action.
Rendering and end-to-end device success remain operational followups, regardless
of a recent timestamp. The JSON result always keeps
`operational_health_confirmed: false` to make that limit explicit.

Exit `0` means the assessment ran without observed attention conditions; missing
consent or invalid arguments return `2`. Denial, unavailable evidence, failed or
stale observed checks return `1`. Unassessed followups still require attention
from an operator and must not be interpreted as verified health.

The installer offers an authenticated, read-only diagnostic download at
`install/install.php?view=diagnostics`. It contains numeric version identifiers,
the PHP runtime, execution state, attempt, derived phase and numbered requirement
and health results. Numbers correspond to the respective displayed tables. It deliberately
omits configuration, usernames, hostnames, paths, discovery ranges, review
fingerprints, credentials, raw errors and log contents. A failed error becomes
the stable `installation_requires_attention` identifier. Preserve the application
revision alongside the download when requesting support. The response is marked
private/no-store; operators must still handle the downloaded file according to
their support policy.

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

Post-install checks distinguish confirmed schema and receipt evidence from work
still requiring operator verification. A recent saved local-poller start is
assessed using the native scheduling tolerance and configured collector ID. It
confirms only a recorded start. Missing timestamps remain unassessed; malformed,
future or stale timestamps require attention. Graph updates, completed device
collection and isolated backup restoration require separate operational checks.

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
Use [the first-time usability protocol](installer-usability.md) to record human
acceptance independently of automated checks.

## Typed support services

The 42 original functions in `install/functions.php` retain their names, argument
order and default arguments for historical upgrade scripts and native callers.
Their implementation now lives in 14 typed services under
`src/Platform/Infrastructure/Legacy/Installer*.php`. The compatibility functions
perform late class loading, native global/session binding and delegation. A
small diagnostic-reset binding clears the native last-error flag before each
migration read, including reads initiated inside another service method.

| Responsibility | Shared implementation |
| --- | --- |
| Executable defaults, package metadata and conversion catalog | `InstallerToolPaths`, `InstallerTemplateCatalog`, `InstallerTableCatalog` |
| Default values and local configuration writes | `InstallerDefaultSettings`, `InstallerConfigurationWriter` |
| Migration statements, status values and progress | `InstallerMigrationDatabase`, `InstallerMigrationProgress`, domain `MigrationStatus` |
| Native diagnostics and installation logs | `InstallerDatabaseDiagnostics`, `InstallerLogger` |
| Secret initialization, colors and automation repair | `InstallerCsrfSecret`, `InstallerColorImport`, `InstallerAutomationRepair` |
| Collector registration and synchronization | `InstallerCollectorConfiguration`, `InstallerCollectorSynchronization` |

Symfony discovers the services through the existing infrastructure resource.
The native backend receives the package/table providers through constructor
injection. Native callers use the same implementations without booting another
kernel. The native engine and versioned historical upgrade scripts remain in
use; this slice does not retire them or certify every legacy helper contract.
PHP 8.4 and the locked Symfony 7.4 requirements are unchanged. New code uses
strict types, readonly services, promoted dependencies, a backed status enum,
explicit return types and PHPDoc collection contracts.

Intentional corrections include exact table-name metadata lookup, explicit
remote-connection selection for collector identity, checked file writes,
exclusive external-secret initialization and failure propagation before a
successful completion report. Migration progress retains native status values,
version keys and SQL structure while withholding bound values and raw database
error values. Unconfirmed replication acknowledgement remains a failed outcome;
it does not undo data already copied to a collector. Automation rules for
unselected templates remain unchanged until both references exist; failed
database lookups still stop repair, and no missing reference is written as zero.

Table discovery allows tables introduced by later upgrade scripts to be absent
before migration. A confirmed zero-row metadata result is omitted from the
conversion list; a failed metadata query still stops installation.

Deploy the compatibility file, native engine change, service classes, backend,
and regenerated catalogs together. Stop and drain installer workers before
cutover or rollback, keep the shared application secret unchanged and rebuild
the application caches in the normal deployment environment. No schema or
configuration migration accompanies this extraction. Code rollback does not
undo completed upgrade statements, collector registrations or initialized files;
keep the existing database/configuration/key backup and recovery prerequisites.
The scope-specific tests run through the existing Symfony and native/Pest
runners with explicitly configured disposable databases. Source-bound local
results and review notes are retained under `var/installer-services`.


## Application execution sequence

`Platform\Application\Installer\InstallationExecution` now coordinates the
admitted worker through `InstallationActions`. The native `Installer` implements
that capability boundary and retains its database, filesystem, progress and
historical upgrade operations. Persisted mode numbers are represented by the
`InstallationMode` backed enum; native mode constants remain compatible.

The sequence preserves storage checks before cache/schema work, secret
initialization, table conversion, mode-specific operations, plugin cleanup,
default migration, primary-only reference setup and confirmed version
publication. Collector identity is read after preceding operations. Returned
failures skip later mutation phases and select the native error step. Exceptions
propagate without publishing a successful completion. Collector synchronization
retains pending-work accounting and its original position after version
confirmation. This is not a transaction over the entire installation.

The existing native worker still owns admission, exclusion and process lifetime;
the application sequence is not an independently authorized HTTP or CLI entry
point. There is one active execution path, and the phase implementations and
historical scripts remain native. No dependency, runtime or schema changes are
required. Deploy the enum, capability interface, application service and native
engine together, following the worker-drain and rollback prerequisites above.
Current scoped evidence is retained in `var/installer-execution`.


## Confirmed version persistence

`Platform\Infrastructure\Persistence\InstallerVersionWriter` now owns the
existing migration-marker transaction. `Installer::recordUpgradeVersion()` still
selects the exact native PDO session, then delegates to the typed service;
`recordInstalledVersion()` and the native CLI retain their existing contracts.

The writer refuses caller-owned transactions, unsupported drivers, temporary
version-table shadows and non-InnoDB MySQL tables. It reads the previous marker,
binds the update/insert, confirms exact readback and confirms commit before
returning success. Cleanup applies only to its own active transaction. An
uncertain commit or cleanup returns failure without claiming that already
committed work was undone. Intermediate migration markers remain available for
normal retry from the last confirmed version.

The extraction preserves the native transaction body, selected connection,
boolean results and error-handling contract. PDO status is explicitly reread
after each operation; static analysis must not cache an earlier successful
status. No schema, supported-runtime or dependency changes accompany this phase.
Deploy the service and compatibility method together under the existing
worker-drain procedure. Code rollback does not undo committed migration markers
or schema changes. Evidence is retained in `var/installer-version`.
