# Symfony migration on main

This work is limited to `main`. The runtime floor, dependency distribution and
application structure on `lts/1.2` are unchanged.

The target is [DDD modules with hexagonal internals](architecture.md), composed by Symfony.

## Foundation

Main requires PHP 8.2 or later. `mise.toml` selects PHP 8.3.33 for development,
Node 22.22.2 for asset builds and Python 3.12.12 for the behavioral harness and
release builder. CI checks the application on PHP 8.2, 8.3 and 8.4.

Symfony 7.4 owns the migrated application's request lifecycle, service container,
routes, controllers, responses, console and Twig rendering. Both `public/index.php`
and the compatibility URL `app.php` enter the Symfony kernel directly. Neither
loads `include/global.php`, `include/auth.php`, nor a legacy page script.

Existing pages and scheduled commands still use the legacy bootstrap. Keep the
legacy deployment available during migration: login, logout, editing and plugins
have not yet moved. Do not switch the whole installation's document root to
`public/` until routing for those remaining features is explicitly configured.

## Shared identity compatibility

`GET /session` (also HEAD) returns the authenticated user's ID and username.
In an existing deployment use `/app.php/session`; the server must support PHP
PATH_INFO. The equivalent route through `public/index.php` now accepts the same
valid session, because Symfony owns the complete request lifecycle.

An injected IdentityAccess adapter reads the existing session and rechecks the
account and console realm 8, including enabled-group grants, using prepared SQL.
Disabled, locked and deleted accounts revoke the presented session. Guest and
pending-password-change identities are rejected. Anonymous and ineligible
requests return JSON 401; they do not render a legacy login page. Visit the
existing login/password-change flow first. Legacy login, remember-me restoration,
Basic/LDAP authentication, session rotation and logout still issue or revoke
credentials; the new routes do not authenticate headers or remember-me cookies.
Provider-specific flows remain to be migrated and validated separately.

Native file sessions and the legacy database session schema are supported. The
adapter preserves the session name, cookie scope, root ownership and payload.
Its database handler reads existing credentials and touches access time, but
cannot create a login or overwrite the legacy session payload. Expired database
sessions fail closed. Symfony's separate session bag remains disabled to avoid a
second payload format during this transition. Application/domain code sees only
Actor and access contracts, not PHP session globals.

Installation configuration is read from the fixed trusted `include/config.php`
path by a Platform adapter; no request data controls that include. Connection
credentials are never part of a response. This slice supports the primary MySQL
installation; remote-poller database routing is not yet migrated. Database TLS
requires a CA and verifies the server certificate. HTTPS-only installations reject
insecure authenticated requests, and Symfony emits response security headers.
Use request-per-process PHP deployment; long-running application workers require
an explicit session-lifecycle migration before enabling them.

## Inventory read slice

Open `/app.php/inventory/devices` after logging in. Symfony routes the request to
`DeviceListController`, which invokes `Inventory\Application\Query\ListDevices`.
The use case obtains identity through IdentityAccess's public `ConsoleAccess`
contract, requires device realm 3 in addition to console realm 8, then queries
its `DeviceCatalog` port. The legacy-schema adapter applies user/group visibility
before pagination and projects only ID, name, hostname, enabled state and status.
SNMP secrets and notes are never selected or returned.

Twig renders `templates/inventory/devices.html.twig`, including escaped data,
search/filter controls, an empty state and previous/next links. A JSON read
representation is available at `/app.php/inventory/devices.json`. Both accept
`q`, `state=all|enabled|disabled`, `page`, and `size=25|50|100`. Search matches
literal text rather than treating percent or underscore as SQL wildcards.
Results have a stable name/ID ordering; lookahead avoids stale permission counts.
All responses are private/no-store, and mutation methods are rejected.

The adapter projects the four legacy graph/device visibility modes without the
legacy fast path that overlooks device exceptions. Default-allow exceptions are
explicitly checked. The explicit state filter replaces legacy saved display
preferences for this new screen. `host.php` remains operational; advanced filters,
exports, edits, bulk actions, plugin-provided list hooks/columns and navigation
cutover are remaining migration work, not claimed parity.

Run the module/kernel checks with `composer test`. Run real HTTP/database checks:

```sh
mise exec -- python tests/Symfony/session_bridge.py
mise exec -- python tests/Symfony/session_bridge.py --database-sessions
```

These create and remove a disposable Docker stack. They check session sharing,
account restrictions, group realms, visibility modes, device exceptions, search,
paging, escaping and input rejection. CI runs both session configurations.

## Source installation

Install the selected runtimes with `mise install`, then:

```sh
mise exec -- php "$(command -v composer)" install
mise exec -- npm ci --ignore-scripts
mise exec -- npm run build
mise exec -- php bin/console about
mise exec -- php "$(command -v composer)" test
```

Composer generates `include/vendor/`; npm supplies browser packages and the
build generates legacy-compatible URLs in `include/js/`, `include/fa/` and
`include/vendor/flag-icons/`. None of these generated distributions belongs in
Git. The existing vendor location is retained so plugins and legacy includes
do not need to change together with the framework migration.

Run the separate Symfony development endpoint with:

```sh
mise exec -- php -S 127.0.0.1:8080 -t public public/index.php
```

The bootstrap defaults to `APP_ENV=prod` and `APP_DEBUG=0`. Supply configuration
through the process environment; it does not read `.env` files. Configure a
deployment-specific `APP_SECRET` before enabling features that use it. There is
no committed application secret. Only `public/` may be exposed by a Symfony
web-server configuration; the existing legacy deployment is not reconfigured.

## Transitional dependencies

`tools/dependencies/legacy-files.json` pins the exact source revision, archive SHA-256 and individual SHA-256
of the remaining legacy compatibility files. Composer's post-install/update
step retrieves only those files from a pinned Kadupul source archive and checks
every selected file before writing. Existing matching files need no download.
PHPMailer, CSRF Magic and some old translation/SNMP/diff helpers contain local
behavior or security fixes; this preserves those fixes without tracking their
entire distributions. These snapshots are **not** independently updated or
audited by Composer or Dependabot. Replace them feature by feature below.

No installation CSRF secret is downloaded or included in a release. Existing
installation secrets are left alone; the legacy application initializes fresh
installation state through its existing setup path.

The npm build preserves the checked-in DOMPurify, D3 and jQuery UI patch recipes
and upstream checksums in `tools/dependencies/assets.json`. Font Awesome retains
the existing `fa-circle-thin` alias. The small unminified tablesorter pager stays
as pinned source because the npm distribution omits it. Other small legacy
browser libraries remain until their owning screens migrate.

## Offline installation

Build on a connected machine with the required PHP extensions and selected
runtimes. New source files must be staged so the release source inventory includes
them; ignored configuration, secrets, caches and dependencies are never copied.

```sh
mise exec -- python tools/build-offline.py
```

The builder installs locked production PHP dependencies into a clean staging
directory, builds npm assets, verifies the Symfony container, and writes
`dist/kadupul-offline.tar.gz` and its SHA-256 checksum. It includes dependency
licenses, lockfiles and a source hash manifest, but no `node_modules`, development
PHP test runner, database configuration, compiled cache or CSRF secret.

Transfer the archive and checksum to the disconnected host, verify the checksum,
and extract it. Neither Composer nor Node nor internet access is needed on that
host. PHP and its extensions, MySQL/MariaDB, RRDtool and net-snmp are still host
prerequisites; the archive does not bundle operating-system packages. Configure
the legacy installer and writable state directories as for a normal installation.
The web installer must be able to create its installation-specific secret in
`include/vendor/csrf/` (or the configured `path_csrf_secret` location).

`tools/verify-offline.php`, run from the extracted directory, verifies framework
boot, PHP autoloading, compatibility file hashes and browser assets. CI runs it
in a container with `--network none`; this is not a substitute for the full
database-backed installation/polling harness. The release workflow builds and
attaches the offline archive in addition to the existing release outputs.

## Replacement sequence

| Existing dependency | Destination | Migration condition |
| --- | --- | --- |
| CSRF Magic | Symfony Security CSRF and Forms | Shared identity/session ownership and mutation tests |
| PHPMailer | Symfony Mailer and Mime | SMTP, sendmail, attachments and failure behavior reproduced |
| gettext helpers | Symfony Translation | Existing catalogs, plural forms and locale fallback verified |
| HTML Purifier | Evaluate Symfony HTML Sanitizer | Existing accepted markup and security fixtures reproduced |
| jQuery UI and tablesorter | Twig, Symfony UX, server-side filtering | Migrate each screen and its keyboard/accessibility behavior |
| phpseclib | Retain maintained Composer package | SSH and existing key compatibility remain necessary |
| DOMPurify | Retain while client-side HTML needs sanitizing | Server-side sanitization does not protect later DOM mutations |
| SNMP and RRDtool | Retain behind application services | Symfony does not replace collection or RRD storage |

Dependabot targets main for root/test Composer, root/E2E npm, Actions and Docker
dependencies. Local patch checks intentionally fail if an update needs a reviewed
patch or checksum refresh. No automated merge is enabled.

## Baseline

The pre-migration source is `c035db65f` on main. The original upstream goldens
remain untouched; `tests/Golden/symfony-baseline/php-8.2/` captures this main
revision's 34 scenarios, including login, device create/delete, SNMP, graph
creation, poller acknowledgements, plugin hooks and fault paths.

A second pre-change run matched 33 scenarios. `poller/device-unreachable`
differed only in its unnormalized `Total[...]` elapsed time (`0.0211` versus
`0.0203` seconds). Both runs returned zero and recorded five RRD acknowledgements.
This is recorded as baseline nondeterminism, not a migration regression or an
approved golden rewrite.

The existing coverage suite aborts at `tests/HandOff/AggregateDataHandoffTest.php`
because `lib/type_secure.php` is missing, on both the local runtime and the Linux
container. Focused CSV tests passed (4 tests); header tests also passed. These
pre-existing failures must not be represented as a passing full test suite.

The dependency-installed candidate also completed all 34 scenarios. Comparing
scenario payloads directly against the pre-change capture gave 33 exact matches;
the only difference was the same elapsed-time field (`0.0494` seconds). This
comparison is not a claim of identical build provenance: the Docker dependency
build inputs intentionally changed.

The security inventories exclude generated Symfony cache (`var/`), Font Awesome
(`include/fa/`) and npm packages. The four new reviewed inventory entries are CLI-only build/verification
operations: a fixed bootstrap under the current release directory, a pinned HTTPS
archive download, its temporary file, and checksum-verified writes to allowlisted
legacy dependency paths. No HTTP request data controls these operations.

Foundation validation before the Inventory slice: Symfony kernel/console/Twig/identity tests passed (5 tests, 29
assertions), asset patch tests pass (19 tests), RSA compatibility passes, mailer
compatibility passes (16 tests), HTML Purifier/runtime-floor checks pass (2 tests),
and CSV paths pass (4 tests). Offline verification succeeded on PHP 8.2 with
Docker networking disabled. The standalone `CsrfHardeningTest` retains one
pre-existing source-text assertion failure: it expects a literal `'delete'` in
unchanged `include/global.php`; its other three checks pass.

The production Docker image builds successfully. An initial attempt exhausted
the local Docker build filesystem; a subsequent complete build passed. The
application harness image and disconnected archive verification also completed.

The shared-authentication HTTP suite passes with both file and database sessions,
including direct and enabled-group grants, revocation, disabled/locked/deleted
accounts, mandatory password changes and legacy logout. These checks do not
claim coverage of every external authentication provider or plugin.

The Inventory slice adds architecture boundary tests and application/domain tests.
The configuration include added by this slice is allowlisted in the security
inventories: its path comes exclusively from Symfony's project directory, and
the loaded file is trusted installation configuration, not a request-selected
script or legacy application bootstrap.

Inventory validation: module/kernel/architecture tests pass (16 tests). The HTTP
suite verifies shared sessions, all four legacy visibility modes, deny exceptions,
enabled/disabled group grants, paging, escaped Twig output and malformed inputs.
The dependency-complete archive boots the Inventory route without network access.
