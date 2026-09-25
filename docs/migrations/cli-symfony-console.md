# Command-line tools on Symfony Console

Status: foundation implemented. Scope: `main` only. `lts/1.2` keeps its
`cli/` scripts unchanged.

## Goal

Rewrite the 46 scripts in `cli/` as Symfony Console commands that follow the
migration's architecture: a thin `#[AsCommand]` class calls an application use
case through a port, and the legacy `lib/` code sits behind an infrastructure
adapter. `RowCacheCommand` and `TestMailCommand` are the existing precedent.

Operators' scripts, cron entries and plugins keep working. Each migrated
`cli/<name>.php` becomes a forwarding shim that accepts the same flags and
reproduces the same output and exit codes.

The poller and daemon entry points (`poller.php`, `cmd.php`,
`script_server.php`, `cactid.php` and the `poller_*.php` jobs) are out of
scope. Cron, spine and the poller depend on their exact arguments, output and
timing, so they get their own design later.

## Success criteria

- Every migrated script passes a parity suite: its shim produces the same
  stdout, stderr, exit code and database effect as the frozen original, apart
  from differences each case lists explicitly.
- Commands read no globals and run no SQL. Authorization, validation and
  persistence go through the owning module's use cases and ports.
- Each command offers `--json` output with stable keys, and Symfony help and
  exit codes when called through `bin/console`.
- CI stays green on every PR, including SonarCloud's 80% coverage on new code.

## Shared foundation

It lives in `src/Platform/Infrastructure/Symfony/Console/`.

### Shim runner

A migrated script shrinks to a guard and one call:

```php
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        http_response_code(404);
    }
    exit;
}
require dirname(__DIR__) . '/include/vendor/autoload.php';
exit(\Kadupul\Platform\Infrastructure\Symfony\Console\LegacyCli::run(
    'kadupul:device:add', AddDeviceLegacyArgs::class, $_SERVER['argv']));
```

`LegacyCli::run()` boots the kernel through `config/bootstrap.php`, translates
the old arguments with the named map, and runs the command in-process in legacy
output mode. It prints one deprecation line to stderr, which
`KADUPUL_CLI_QUIET_DEPRECATION=1` suppresses for cron. The shim neither starts
a subprocess nor loads `lib/`.

The guard is the same one `bin/console` uses, including the `headers_sent()`
check that keeps PHP-FPM from answering 200 after a shebang line (#377). The
shim does not include `include/cli_check.php`, because that file also loads the
legacy bootstrap in `include/global.php`.

### Legacy argument maps

One small class per script declares the translation from old arguments to new
options:

- old flag to new option, for example `--ip=x` to `--hostname=x`;
- aliases the old parser accepted, for example `--ping_timeout`;
- old value spellings, for example the `--avail=` names;
- old special modes: `--version`, `--help`, `--quiet` and the `--list-*`
  listings, each routed to a command or option.

An argument the map does not declare stops with the old error text and exit
code 1. Nothing is dropped silently. The maps are data, so a unit test checks
every flag the old parser accepted against the map.

### Results and renderers

A command never prints its result piecemeal. It builds the whole result
first, then writes it one of two ways:

- Human output (the default under `bin/console`) goes through `SymfonyStyle`
  in the command itself, with errors on stderr.
- `--json` and shim mode go through one `ResultRenderer`. The command hands
  it a `CommandResult` carrying the JSON object and the historical lines, for
  example `Success - new device-id: (123)` and `ERROR: Invalid Ping Port: (x)`
  followed by the help text. JSON is one object on stdout; legacy lines go to
  stdout, as the originals printed them. Each script's legacy strings live
  next to its argument map.

Exit codes follow the original script in legacy mode, except where the
differences listed in `docs/symfony-migration.md` say otherwise. Under
`bin/console` they are 0 for success, 1 for failure and 2 for invalid input.

### Command-line actor

Use cases authorize an `Actor`. Web requests resolve it from the shared
session. The command line has no session, so a new IdentityAccess adapter
implements `ConsoleOperator`, a contract for command-line use only. It does
not implement the web `ConsoleAccess` contract, so no route can resolve an
operator chosen by a command-line flag:

- The actor is the account named by `--as=<username>`, or, when that is
  absent, the `admin_user` setting. That matches whose authority the old
  scripts effectively used.
- The account must exist, be enabled, not be locked and have no password
  change pending. Each command needs realm 8 (console access) plus the realm
  the web page for the same action needs: realm 15 for installation
  administration, realm 26 for the installer's schema changes. Realms are read
  the way `include/auth.php` reads them, directly or from an enabled group.
  While nobody holds realm 26, a direct realm 15 grant counts for it, as on
  the web, but nothing is written. The web page itself needs no realm 8, so
  the command-line check is stricter. A command refuses to run otherwise.
- The account is read from the database the command works on. Collectors
  hold a replicated copy of `user_auth*` and `settings`, so a collector run
  with `--local` needs no main database. An unreachable main database never
  falls back to the local copy.
- Audit records name that actor.

Shell access with a readable `include/config.php` already grants database
access, so this adapter adds attribution and consistent checks rather than a
new boundary. It is still identity code, so it is delegated with full
verification, like any other IdentityAccess change.

### Parity harness

For each migrated script there is a table of recorded cases. Each case runs
both the frozen original, kept under `tests/Fixtures/legacy-cli/` and never
shipped, and the shim against the same seeded MariaDB. It compares stdout,
stderr, the exit code and the resulting rows. Allowed differences, such as the
deprecation line or generated ids, are listed per case. The harness runs in
the Symfony integration job, so its coverage reaches SonarCloud.

## Database access through DoctrineBundle

Commands, their adapters and the Inventory read path reach the database through
Doctrine DBAL connections that DoctrineBundle configures, not through PDO
objects built by hand.

- `doctrine/doctrine-bundle` (3.3, which supports PHP 8.4, DBAL 4 and
  Symfony 7.4) is added. It brings `doctrine/persistence` and
  `symfony/doctrine-bridge`. There is no ORM and there are no migrations; the
  schema ownership rule in `docs/architecture.md` is unchanged.
- `config/packages/doctrine.yaml` declares three connections:
  - `local`: the installation's own database (`$database_*`);
  - `main`: the main database. On the primary this is the local database. On
    a remote collector it is `$rdatabase_*`. An offline collector, or one with
    no main configuration, has none;
  - `web`: what `InstallationConfiguration::values()` resolves for web
    requests, including the optional read-only user from #350. It replaces
    the hand-built `kadupul.inventory.read_database`.
- Credentials still come only from `include/config.php`. One DBAL driver
  middleware, `InstallationConnectionMiddleware`, fills in host, port,
  database, user, password, charset and the TLS driver options when a
  connection first connects. It reads them through `InstallationConfiguration`.
  `doctrine.yaml` names only the connection and its target, so no credential
  passes through container parameters, the compiled container or a `.env`
  file.
- The middleware applies the validation and TLS rules of
  `InstallationDatabase`: `;` and NUL are rejected in host and database
  names, TLS requires a CA, and certificates are verified.
- A missing `main` connection fails when first used, with
  `Main database is not configured.`, and never when the container is built.
  A command that works on `local` therefore still runs on a collector without
  a main configuration.
- Adapters receive connections by autowiring (`Connection $localConnection`,
  `Connection $mainConnection`). Whether the installation is a remote
  collector comes from a small `CollectorIdentity` service, not from a
  connection.
- Credential arrays keep `#[\SensitiveParameter]`, and passwords never appear
  in exception messages.

## Symfony and PHP conventions

The command-line tools are written as idiomatic Symfony 7.4 applications for
PHP 8.4, the floor on `main`, and use a Symfony component wherever one does
the job.

Commands:

- A command is an invokable `#[AsCommand]` class with no `Command`
  subclass. Its input is declared on `__invoke()` with `#[Option]` and
  `#[Argument]`, grouped into an input object with `#[MapInput]` when there is
  more than one. Backed enums type options that take a fixed set of values.
- `__invoke()` receives `SymfonyStyle` for human output and returns
  `Command::SUCCESS`, `Command::FAILURE` or `Command::INVALID`. Invalid input
  is rejected with `Command::INVALID` before any use case runs.
- The use case arrives as a typed, autowired constructor argument. No
  closures are injected.
- Output mode (human, JSON or legacy) is an `OutputMode` enum carried by a
  `CliPresentation` service. The shim sets it through the kernel before it
  runs the command, so there are no hidden command-line options.

Components:

- Console for commands and output; DoctrineBundle and DBAL for data;
  Filesystem for files; Process for external commands; Clock for time.
- Application code reaches a component only through its module's port, as
  `tests/Symfony/ArchitectureTest.php` requires. For example, the use case
  reads time from a `Clock` port whose adapter wraps Symfony Clock.
- A component is added only when a task uses it. Lock, Validator and
  Messenger are left out until a tool needs them.

PHP 8.4:

- `final readonly` classes for services and value objects, backed enums for
  closed sets (such as the database target), `#[\Override]` on implemented
  methods, and typed class constants.
- The driver-specific `\Pdo\Mysql::ATTR_*` constants, not the
  `\PDO::MYSQL_ATTR_*` ones that PHP 8.5 deprecates.
- `array_any()` and `array_find()` where they state the intent.
- No `@` error suppression. Errors are caught as typed exceptions.
- File I/O goes through Symfony Filesystem, declared as a direct
  dependency: `dumpFile()` for atomic writes, `appendToFile(..., lock: true)`
  for appends, `readFile()` for reads. Failures surface as `IOException`
  rather than a silenced `@` call; best-effort writes catch it explicitly.
- External commands go through Symfony Process with an argument array, never a
  shell string, and an explicit timeout, unless a command's own section says
  otherwise. Caller input never reaches a shell.

## Write commands

These rules apply to every command that changes the schema or rows.
`kadupul:database:convert-tables` and `kadupul:database:widen-id-columns`
are the first.

- **Flags.** A write command runs when called if its original did, and needs
  `--confirm` only where its original did, as `remove_device` does. Under
  `bin/console` every write command also takes `--dry-run`: it reads the same
  state, reports each statement it would run, and runs none. A dry run
  passes the same realm check on the same target database; a refusal is
  audited as denied, and a dry run records no per-statement audit events.
  Shims do not accept `--dry-run`, because no original had it.
- **Decide, then apply.** The use case reads the state it needs once, decides
  every change in Domain code that touches no database, then applies the
  changes in order. Human, JSON and legacy output are all built from that one
  result.
- **Statement boundaries.** MySQL and MariaDB commit DDL implicitly, so a
  schema change runs as one `ALTER TABLE` per table, outside any transaction.
  A failed statement is reported, logged and audited, and the run goes on to
  the next table, as the originals did. Nothing is retried and nothing claims
  to roll back. A command that changes rows runs its use case inside one
  `Connection::transactional()` call, unless a command's own section says
  otherwise, and never issues DDL inside it.
- **Target database.** On a remote collector the command writes to the main
  database unless `--local` is given, which is what the originals did. This
  holds unless a command's own section says otherwise. A missing main
  configuration fails with `Main database is not configured.` and never
  falls back to the local database. The schema is read from the
  target connection's own `DATABASE()`, not from the local database's name.
- **Authorization.** The command needs the realm the web UI requires for the
  same action, cited in the command's entry in `docs/symfony-migration.md`,
  checked through `ConsoleOperator` on the target database before any
  statement. A refusal runs nothing and records a denied audit event.
- **Identifiers and values.** Table names in DDL come from the target's
  `information_schema` or `SHOW TABLES`, or from class constants; column names
  come from `information_schema.COLUMNS`. An operator-supplied name is used only if it is an
  exact match in that list. Every identifier is quoted with
  `quoteSingleIdentifier()`. Engines, charsets, collations, row formats and
  column types come from enums or constants. Values such as column defaults
  are quoted with the platform's `quoteStringLiteral()`.
- **Operator log.** A command writes the lines its original wrote to
  `cacti.log` through `LegacyOperatorLog`, with the same environ, text and
  level. A line `cacti_log()` wrote with a level is written only when
  `log_verbosity` allows it, by the rule in `lib/functions.php:1343-1358`;
  selective debug is not honoured. A failed statement writes the server's own
  message the way `db_execute()` did, and, at debug verbosity only, the line
  with its error number and statement (`lib/database.php:638-639`), without
  the backtrace. Log writes are best effort and never change the result.
- **Audit.** Each statement sent records one `AuditEvent` through
  `IdentityAccess\Contract\AuditTrail`: the actor, the command, the target
  database, the dry-run flag, the table and whether it succeeded. A table
  name the catalog does not list records one `failed` event, since no
  statement is sent for it. A refusal records one denied event, which also
  carries the target database. The target is `database-table` with the id
  `<database>:<table>`; a table name outside `[A-Za-z0-9_.:-]`, or longer
  than 64 characters, uses the type `database-table-sha256` with the id
  `<database>:` and the SHA-256 of the name, so every attempt is recorded. A
  refused dry run records the command's action with a `.dry-run` suffix, for
  example `database.convert-tables.dry-run`, so a query for refusals must
  match both action names. As in `SiteWriteAudit`, the sink is best effort
  and never replaces the result of a statement that already ran; an event
  the audit schema rejects is a bug and fails the command. Run write commands as the web server's user, so that
  `log/kadupul-audit.jsonl` and `log/cacti.log` stay writable by web requests.
- **Exit codes and results.** In legacy mode the exit code is the original's,
  including 0 after its own validation errors and after failed statements.
  Under `bin/console` it is 0 when every statement succeeded, 1 when any
  failed, the run stopped or the operator was refused, and 2 for invalid
  input. JSON `status` is `ok`,
  `partial`, `failed`, `invalid` or `denied`. Database exception text never
  reaches stdout or stderr.
- **Parity.** Each scenario resets the same starting state before the
  original and before the shim, and compares the resulting schema
  (`information_schema.TABLES`, `information_schema.COLUMNS`,
  `SHOW CREATE TABLE`) or rows, as well as stdout, stderr and the exit code.
  The security checks are listed in `tests/Symfony/merge_coverage.php`.

### Audit

`kadupul:database:audit` follows the rules above, with these exceptions and
additions, which the original requires:

- **Target database.** `audit_database.php` refused a remote collector
  outright, before reading its arguments, and the audit does the same.
- **Confirmation.** `--repair` is the one write mode that plans by default.
  It changes the schema only with `--force`, or after the operator has seen
  the plan on a terminal and answered yes to a question that defaults to no.
  The shim keeps the original's immediate repair.
- **Transactions.** The audit's only row writes are the baseline inserts
  into `table_columns` and `table_indexes`. They run in one
  `transactional()`, after the DDL that resets the two tables, which commits
  on its own. A refused insert rolls back every row, and the run reports
  that the baseline did not load.
- **Names that do not exist yet.** A column or index an `ALTER` adds takes
  its name from the audit baseline parsed out of `docs/audit_schema.sql`, a
  shipped file, and the name must match `^[A-Za-z0-9_$-]{1,64}$`. The table
  name still comes from the target's catalog. Column types, `EXTRA` values,
  `USING` algorithms and table character sets come from closed lists; a
  clause that has no typed form is reported and its table's statement is not
  sent. The text of `docs/audit_schema.sql` is parsed as data and never sent.
- **Workers.** `lib/` code that needs the legacy bootstrap runs in a `bin/`
  worker that an adapter starts through Symfony Process, with an argument
  array, JSON on stdin and a result marker on stdout, as the device workers
  do. A worker checks nothing itself; only its adapter names it, which
  `tests/Symfony/ArchitectureTest.php` enforces. The upgrade worker has no
  timeout: stopping it half way leaves the database between two versions,
  and the original ran it to the end.
- **Audit events for other steps.** An upgrade, the audit tables' reset or
  a dump records one event with the type `database-maintenance` and the id
  `<database>:<step>`, where `<step>` is a fixed name (`upgrade`,
  `audit-schema-reset`, `audit-schema-export`). Every `ALTER TABLE`,
  including the audit tables' reload, records a `database-table` event. The
  audit tables' reset is one step; their reload is recorded only when it
  ran, so a missing or unparsable file records the reset alone. The action
  is `database.audit`.

## Pilot: device commands

| Script | Command | Use case |
|---|---|---|
| `add_device.php` | `kadupul:device:add` | `CreateDevice`, with `PrepareDeviceCreation` for defaults |
| `change_device.php` | `kadupul:device:edit` | `EditDevice`, with `FindEditableDevice` for the current revision |
| `remove_device.php` | `kadupul:device:remove` | `RemoveDevices`, with `PrepareDeviceRemoval` |
| `host_update_template.php` | `kadupul:device:assign-template` | `AssignDeviceTemplate` |
| `poller_reindex_hosts.php` | `kadupul:device:reindex` | new `ReindexDevices` use case and port |
| `push_out_hosts.php` | `kadupul:device:push-out` | new `PushOutDevices` use case and port |

The `--list-host-templates` and `--list-communities` listings become
`kadupul:device:list-templates` and `kadupul:device:list-communities`. The
shims route the old flags to them.

`change_device` has no revision on the command line. The command reads the
current revision through `FindEditableDevice` and applies the edit at once, so
a concurrent edit between the two steps surfaces as the existing stale-edit
conflict instead of a lost update.

`remove_device` selects devices by `--id`, `--description` or `--ip`. The
selection goes through `ListDevices`, so the command removes only devices the
actor can see. Without `--confirm` it only lists what would be removed, as
the original does.

The pilot starts after the open device stack (#276 to #316) and the
`change_device` fix (#320) have merged. The shared foundation can land before
them.

## Order of work

1. Foundation: shim runner, argument maps, renderers, CLI actor adapter,
   parity harness, with a small non-device command as the first user.
2. Device pilot, one PR per script or pair of scripts.
3. Remaining groups, each its own spec and plan: graphs and trees; data
   sources and queries; templates and import; users and permissions; database
   and install tools; RRD tools; plugins and miscellaneous scripts.
4. Shim removal, in a later major release once the parity suite has run clean
   for a full release cycle.

## Risks

- Output parity is harder than it looks: operators parse these lines. The
  parity harness and per-case allowed differences are the control.
- `lib/` functions reach globals and plugin hooks. Adapters must preserve hook
  order, as `LegacyDeviceCreator` already does for `api_device_save`.
- Several scripts overlap the other session's open work. Before each group
  starts, the plan checks open PRs for the files it touches.
- Reformatting a legacy script to PER-CS counts every line as new code for
  SonarCloud. Shims replace scripts entirely, so the new code is the shim and
  the command, both of which the parity tests cover.
