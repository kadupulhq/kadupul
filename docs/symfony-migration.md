# Symfony migration on main

This work is limited to `main`. The runtime floor, dependency distribution and
application structure on `lts/1.2` are unchanged.

The target is [DDD modules with hexagonal internals](architecture.md), composed by Symfony.

## Foundation

Main requires PHP 8.4 or later. `mise.toml` selects PHP 8.4.25 for development,
Node 22.22.2 for asset builds and Python 3.12.12 for the behavioral harness and
release builder. CI checks the application on PHP 8.4.

Symfony 7.4 owns the migrated application's request lifecycle, service container,
routes, controllers, responses, console and Twig rendering. Both `public/index.php`
and the compatibility URL `app.php` enter the Symfony kernel directly. Neither
loads `include/global.php`, `include/auth.php`, nor a legacy page script.

Existing pages and scheduled commands still use the legacy bootstrap. Keep the
legacy deployment available during migration: login, logout, advanced device configuration and plugins
have not yet moved. Do not switch the whole installation's document root to
`public/` until routing for those remaining features is explicitly configured.

For Nginx repository-root deployments, apply the non-public-directory, dotfile,
metadata and internal-PHP deny locations in `tests/e2e/nginx.conf` **before** the
generic PHP location. Nginx does not read `.htaccess`. Preserve the PATH_INFO
handling shown there for `/app.php/...` routes. Never expose `src/`, `config/`,
`templates/`, `bin/`, `tools/`, `tests/` or runtime state as static content or PHP
entry points. The PHP build, verification and assessment tools also reject HTTP
execution before loading dependencies or configuration.

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
before pagination and projects only ID, name, hostname, enabled state, status, location and external ID.
Legacy null location/external ID values are represented as empty strings.
SNMP secrets and notes are never selected or returned.

Twig renders `templates/inventory/devices.html.twig`, including escaped data,
search/filter controls, an empty state and previous/next links. A JSON read
representation is available at `/app.php/inventory/devices.json`. Both accept
`q`, `state=all|enabled|disabled`, `status=all|up|down|recovering|unknown|error|disabled`,
`page`, and `size=25|50|100`. Status matches the displayed column: disabled
devices appear only under Disabled, regardless of their last observed status.
Status and polling-state filters intersect, so contradictory choices return an
empty result. Status filtering happens before pagination and is retained in page
and CSV links. Search matches name, hostname, location and external ID as
literal text rather than treating percent or underscore as SQL wildcards.
Use `sort=name|hostname` and `direction=asc|desc` to choose ordering. The default
is ascending name. Device ID breaks equal-value ties in the same direction,
keeping pages deterministic for an unchanged inventory. Text ordering follows the
installation database collation. Sorting is retained in page and CSV links;
lookahead avoids stale permission counts.
The `site` filter accepts an empty value for all sites, `0` for unassigned
(site ID zero), or a positive site ID. Inventory's `ListDeviceSites` query obtains
choices through the `DeviceSites` port; its Doctrine DBAL adapter applies the
same device visibility rules as the list, reading the policies on its own
connection. Only sites with accessible, non-deleted devices
are named. Hidden, empty and deleted-only sites are omitted. An unavailable
selected site retains a generic label without revealing its name. Search, state,
status and site intersect before pagination. Site selection survives CSV and
editor navigation; this does not change device site assignments.

All responses are private/no-store, and mutation methods are rejected.

“Export this page (CSV)” uses `/app.php/inventory/devices.csv` with the same
filters, ordering, page size and authorization as the list. This is a current-page
download (at most 100 devices), not a complete inventory export. The Symfony
controller invokes the same `ListDevices` application query and passes its read
model to an infrastructure CSV encoder; no export-specific SQL or domain format
dependency is introduced. The file includes ID, name, hostname and displayed status, followed by the
Location and External ID columns, with UTF-8 identification and CSV quoting.
Name, hostname, location and external ID cells always
receive a leading apostrophe to mark them as literal spreadsheet text, including
formula-like values preceded by whitespace. Raw CSV readers retain that prefix.
Empty pages return column headers. Downloads are private/no-store; HEAD returns
headers only and mutation methods are rejected.

The adapter projects the four legacy graph/device visibility modes without the
legacy fast path that overlooks device exceptions. Default-allow exceptions are
explicitly checked. The explicit state filter replaces legacy saved display
preferences for this new screen. `host.php` remains operational; advanced filters,
full exports, advanced edits, bulk actions, plugin-provided list hooks/columns and navigation
cutover are remaining migration work, not claimed parity.

Run the module/kernel checks with `composer test`. Run real HTTP/database checks:

```sh
mise exec -- python tests/Symfony/session_bridge.py
mise exec -- python tests/Symfony/session_bridge.py --database-sessions
```

These create and remove a disposable Docker stack. They check session sharing,
account restrictions, group realms, visibility modes, device exceptions, search,
paging, escaping and input rejection. CI runs both session configurations.

## Site catalog slice

`/app.php/inventory/sites` now lists sites through Symfony and Twig, with the same
read representation at `/app.php/inventory/sites.json`. Inventory's `ListSites`
query requires console access and realm 3 (Sites/Devices/Data), matching the legacy
site administration boundary. Its `SiteCatalog` port returns site names, IDs, city,
state, country and accessible-device counts. All positive-ID sites are listed,
including empty sites and sites with no accessible devices. This is a site
administration view; the device filter still names only sites with visible devices.

Counts apply the existing device visibility policy, read through the Inventory
DBAL connection, exclude deleted devices, and
count each device once even when it has multiple graphs. Count links open the
permission-filtered device list for that site. Neither notes nor other site fields
are selected. Legacy null address fields display as empty text.

Search (`q`) matches name, city, state or country as literal text. `direction=asc|desc`
sorts by name with an ID tie-breaker; `page` and `size=25|50|100` provide bounded
pagination with lookahead. Twig escapes site text and carries filters between
pages. Controller responses are private/no-store. GET/HEAD are supported; Symfony
rejects mutations at routing with a generic 405. Site deletion/duplication, remaining edit fields,
other sort columns, saved preferences and legacy navigation cutover remain to be
migrated. `sites.php` remains operational.

## Site editing slice

Site names now open `/app.php/inventory/sites/{id}/edit`. Symfony Forms and Twig
edit the name and notes through Inventory's Site aggregate, EditSite command and
SiteEditor port. Console access and realm 3 authorize all site edits, including
empty sites, consistent with legacy site administration. Anonymous requests return
401, revoked access 403, and absent/deleted sites 404. Editing never creates a missing site; creation has its own route below.

Names are trimmed and require 1–100 Unicode characters; notes preserve whitespace
and allow up to 1,024 Unicode characters, matching the database column. Symfony
normalizes textarea line endings to LF. The domain enforces character limits;
browser UTF-16 maxlength attributes are omitted so astral characters count correctly. Empty notes
clear the field; legacy NULL notes read as empty text. Both fields reject invalid
UTF-8 and NUL characters. Symfony's stateless CSRF protection requires its token and
same-origin evidence. Unknown fields are rejected. Validated list search, order,
page and size survive errors and saves; no supplied return URL is followed.

The legacy-schema adapter uses prepared statements and a local transaction. It
rechecks the actor and realm before acquiring a site-row lock, compares the name/notes
revision under that lock, and updates only those two fields. IdentityAccess uses
current locking reads during this transaction: account, authentication policy,
and the direct or group grants that authorize the write stay locked through
commit. Concurrent revocations wait for the write; revocations committed first
are observed and rejected. Session storage uses its own database connection so
rolling back a rejected site write cannot restore a revoked credential. Stale saves return
409; concurrent address, timezone, map or alternate-ID changes are preserved.
The legacy update-site path has no plugin/poller save hooks or cache invalidation
for existing sites, so this adapter does not bootstrap procedural code or launch
a CLI worker. Site creation's cache effects are outside this slice. Existing legacy
editors do not enforce the new revision protocol, so they can still overwrite later.

Failed persistence attempts roll back when the transaction remains active. An
uncertain failure returns 502 and asks the operator to reload before retrying;
there is no automatic retry. Controller responses are private/no-store. Other settings and destructive/bulk operations remain in `sites.php`.

## Inventory details slice

Device names open `/app.php/inventory/devices/{id}` (GET/HEAD). Symfony invokes
`FindDeviceDetails`, which authorizes through IdentityAccess and reads through
Inventory's `DeviceDetailsReader` port. Its Doctrine DBAL adapter applies the
same visibility rules as the list and selects only the displayed fields. Twig shows
metadata, site, status and escaped plain-text notes; SNMP credentials are never
selected. Missing/hidden/deleted devices return 404, anonymous requests 401, and
revoked device-realm access 403. Responses produced by the details controller are
private/no-store. Unsupported methods receive Symfony’s generic routing-level 405
before the controller runs, without its cache headers or any device data.
Status projection is shared with the list, including Error and
Disabled. A missing site reference shows Unavailable; site ID zero is Unassigned.

The details page, explicit list Edit action, and editor retain the validated list
context. The existing editor remains the only mutation path in this slice.

## Inventory editing slice

Use the Edit action to open `/app.php/inventory/devices/{id}/edit`. Symfony Forms
and Twig edit its name, address, location, external ID, notes and polling state. The Device aggregate validates these
fields; the EditDevice command authorizes through IdentityAccess and saves through
the DeviceEditor port. The list and editor share the same visibility policy.
The polling choice is required and accepts only Enabled or Disabled; missing or
invalid choices are rejected. Polling state participates in the revision so an
older details form cannot overwrite a concurrent enable/disable operation. Saving
uses the legacy device-save path: disabling resets observed status to unknown,
and enabling leaves status discovery to the next poll. Historical data is retained.
Location and external ID are optional Unicode text, limited to 40 characters to
match the existing schema. Empty values clear the fields; neither field implies
uniqueness or changes site membership. Both participate in stale-edit detection
and pass through the existing graph-title and plugin save effects. The new form
does not yet provide the legacy location autocomplete.
Opening a device from the list preserves its search, filters, sorting, page and
page size through validation errors and successful saves. “Back to devices”
returns to that view. Only validated list parameters are carried in `list[...]`;
form actions and links use fixed Symfony routes, never a supplied return URL.
Other settings, including SNMP credentials, templates and poller assignment, remain
in the legacy editor and cannot be submitted through this form.

Symfony's built-in stateless CSRF protection requires the form token and same-origin
browser evidence. Missing or cross-origin evidence fails closed; no second session
payload is introduced. A revision derived from editable fields rejects stale saves
with HTTP 409. Hidden devices return 404; revoked device access prevents saving.

The transitional outbound adapter invokes a fixed PHP CLI worker using Symfony
Process with JSON on stdin, never a shell command assembled from user input. The
worker rechecks account, realms and visibility, locks the current device row,
validates its revision and calls the existing device-save API with fresh settings.
It retains legacy poller/remote synchronization, graph-title updates and host-save
hooks without bootstrapping legacy globals inside Symfony's HTTP process. Successful
saves retain the human-readable actor and device entry. The adapter also
generates an opaque correlation identifier for the worker. After commit or
rollback, the worker records a versioned audit event containing only actor,
action, target, authorization decision and outcome. Denied and
post-authorization failed attempts contain no submitted fields, credentials,
session data, plugin output or exception messages. See
[architecture-alignment.md](architecture-alignment.md) for current sink
limitations. Structured events append to the fixed private
log/kadupul-audit.jsonl file independently of generic Cacti log destination and
verbosity settings. A PHP CLI binary at PHP_BINDIR/php and process execution
must be available to the web runtime.

Local writes use a database transaction. External plugin and remote-collector
effects cannot universally be rolled back. Timeouts or failures report an uncertain
outcome and ask the operator to reload before retrying; writes are never retried
automatically. Existing legacy editors do not enforce the new revision protocol.
Remote collectors and arbitrary third-party plugins require separate parity testing.

The HTTP suite checks invalid/extra fields, CSRF rejection, hidden devices, revoked
realms, stale saves, polling transitions, escaped notes, persistence, graph-title refresh, host-save hooks
and audit attribution with both session handlers. Domain/application tests check
atomic validation and authorization before persistence.

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

Symfony's migrated Twig templates currently request no browser assets. The test
suite rejects static remote script, stylesheet, image, media and frame URLs in
those templates. When a migrated page first needs a browser dependency, pin its
exact version in `package.json` and `package-lock.json`, build it to a local path,
and add that output to `tools/verify-offline.php` before referencing it.

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

Device-edit validation: module/kernel/architecture tests pass on PHP 8.3.33
(25 tests, 1,750 assertions). Both real HTTP session configurations pass, including
the graph-title, plugin-hook and audit checks. Container/Twig lint, staged-content
checks and both security inventories pass. The production image builds and the
extracted offline archive verifies with Docker networking disabled. These focused
checks do not resolve the pre-existing full-suite failures described above.

Polling-state validation: 27 module/kernel/architecture tests pass on PHP 8.3.33
(1,813 assertions). File- and database-session HTTP suites pass for explicit
choices, rejected missing/invalid states, concurrent changes, enable/disable
persistence, status reset, list filters and retained notes. Container/Twig lint,
security inventories and staged-content checks pass; the rebuilt offline archive
verifies with Docker networking disabled.

Location/external-ID validation: 36 module/kernel/architecture tests pass on
PHP 8.3.33 (2,014 assertions). Both HTTP session configurations pass metadata
persistence, Unicode limits, explicit clearing, escaping, stale revisions and
graph-title substitution checks. Container/Twig lint, security inventories and
staged-content checks pass. The rebuilt offline archive verifies with Docker
networking disabled.

Current-page CSV validation: 49 module/kernel/architecture tests pass on PHP
8.3.33 (2,077 assertions). File- and database-session HTTP suites verify matching
list/export visibility and page boundaries, state filters, revoked access,
malformed filters, empty pages, HEAD responses and multiline Unicode/formula-like
cells. Container/Twig lint, security inventories, focused Semgrep and staged-content
checks pass. The rebuilt offline archive verifies with networking disabled.

Shared-session hardening and coverage validation: unknown cookie IDs are rejected
without adopting or creating the supplied ID, and query parameters cannot resume
an authenticated session. Both session-handler HTTP suites pass. The local merged
PHP report measures 459/521 statements (88.1%); the offline builder measures 94.1%
line coverage, and all 40 JavaScript build/maintenance tests pass. Nine malformed
coverage-evidence cases fail closed. These local totals are not a substitute for
Sonar's independently calculated new-code quality gate.

## Inventory translation slices

Symfony Translation now owns UI messages for the site list and name/notes editor, plus the device list,
details and editor.
The initial catalogs are English and French in `config/translations/inventory.*.yaml`.
They cover Twig text, accessible labels, form labels/help, and editor validation and
failure messages. Device status labels and polling choices are translated only in
HTML; stored names, notes, locations and site names remain user data. Polling choice
values stay `enabled`/`disabled`. Dynamic site names remain escaped after parameter substitution.
The HTML language attribute follows the request locale. JSON and CSV representations retain their existing language/data contracts,
including status values and CSV headers.

Platform's `InventoryLocaleSubscriber` runs after routing and before Symfony's locale
listener. IdentityAccess exposes a read-only `LocalePreference` contract backed by
the existing native session and `settings_user.user_language`. No locale global,
gettext bootstrap, session write, or PHP process-wide `setlocale()` is introduced.
Domain/application code still emits its existing errors; the HTTP adapter translates
them for presentation. Those error strings currently serve as catalog IDs.

For migrated Inventory HTML requests carrying cookies, precedence is:

1. `i18n_language_support=0` forces English.
2. Trusted installation `$i18n_force_language`, if supported.
3. Shared-session `sess_user_language`, or saved `user_language` when absent.
4. Weighted browser languages, unless `i18n_auto_detection` disables detection.
5. Installation `i18n_default_language`, then English.

Legacy `en-US`/`en_US` and `fr-FR`/`fr_FR` spellings (including supported-language
regional variants) map to the English/French catalogs. Unsupported values fall
through this precedence; arbitrary paths cannot select catalogs. Anonymous requests
without cookies default to English without opening installation/session storage.
Query parameters such as `language` and `_locale` do not switch or persist a locale.
Use the existing preference screen to change the saved language; an existing login
may retain its session language until the next legacy locale refresh/login.

The existing gettext catalogs and legacy pages are unchanged. Additional languages,
locale-aware dates/numbers and migration of the preference editor
are follow-up slices. New catalogs reside under the already non-public `config/`
directory and are included in offline bundles together with the locked translation
component. LTS is unchanged.


## First Symfony Mailer path

On main, `php bin/console kadupul:mail:test` sends one plain-text test message to
`settings_test_email`, using the installation database and existing mail settings.
Run it as an installation operator with access to `include/config.php`; it sends
real mail when invoked. Success means the SMTP server accepted the message, not
that the recipient received it. Failures return exit code 1 and deliberately omit
raw database/SMTP errors, credentials and recipients, including in verbose mode.
Check server logs before retrying: a lost acknowledgement can leave the send outcome
unknown. The command never automatically resends a message.

Symfony Console and the container compose Alerting's `SendTestMail` application
use case with its `TestMailDelivery` outbound port and a Symfony Mailer adapter.
The application layer has no globals, database calls or Symfony dependencies.
Settings and network access are lazy, so console discovery and health checks do
not require an installed database or SMTP server. No legacy bootstrap is loaded.

This first path requires `settings_how=2` (SMTP), one hostname or IP in
`settings_smtp_host`, port 1–65535, timeout 1–300 seconds, and single bare email
addresses in `settings_from_email` and `settings_test_email`. Sender display name
comes from `settings_from_name`. Missing host/port/timeout settings default to
localhost/25/10; explicitly invalid values fail. Semicolon-separated failover
hosts, PHP mail and sendmail are not supported by this command.

`settings_smtp_secure=none` disables TLS, `tls` requires STARTTLS, and `ssl` uses
implicit TLS; normal certificate/hostname verification remains enabled. ESMTP is
required: HELO fallback is blocked because it can bypass the component's required-TLS
check. A configured SMTP username requires advertised authentication; credentials
are supplied through transport setters, never a DSN. The local tests use loopback
SMTP servers, including authenticated STARTTLS and implicit-TLS delivery with a
temporary test CA, untrusted/wrong-host certificate rejection before authentication,
lost acknowledgements and TLS downgrade refusal. Machine trust is unchanged; these
tests do not establish delivery through a production SMTP server.

Existing web test-mail, report attachments and daemon notifications still use the
legacy mailer. Migrating those callers and removing PHPMailer are follow-up slices.
Composer locks the new component, and offline archives include its production
dependencies. No vendor directories are tracked. LTS is unchanged.


## Administrator notification migration

On main, existing `admin_email()` callers now enter a Symfony-composed Alerting
`NotifyAdministrator` use case through `include/admin_notifications.php`.
Alerting owns the enabled/configured policy and the delivery decision. IdentityAccess
owns account contact lookup and exposes it through `UserContacts`; an Alerting
adapter maps that contract to its recipient model. Domain and application code
contain no globals, legacy functions, database calls or Symfony dependencies.

Legacy infrastructure adapters deliberately reuse `read_config_option()` and the
active collector database connection. This preserves installation defaults,
preferences and remote-collector context without loading a second installation
configuration/database connection. Disabled or unset administrators are checked
before account lookup; unknown accounts and empty addresses retain their warnings.
The bridge catches notification failures so collection continues and logs a generic
uncertain-outcome warning without raw exception messages. SMTP acceptance is logged
without recipient addresses, subjects or credentials.

A single ordinary SMTP host, explicit sender name and bare sender/recipient
addresses now use the same Symfony SMTP sender as `kadupul:mail:test`. Administrative
HTML messages retain a plain-text alternative. Required STARTTLS, implicit TLS,
certificate verification and sanitized errors are shared with the tested mail path.
The existing callers still supply their translated subject/body and control their
own notification frequency; this change introduces no schedule, queue or retries.

Compatibility is chosen before any send attempt. PHP mail, sendmail, host lists or
embedded host protocols/ports, complex address formats, implicit sender-name lookup,
legacy template substitutions and other unsupported SMTP settings retain the
legacy delivery path. The literal SMTP username `0` also retains that path because
Symfony treats it as empty during authentication. Once Symfony SMTP is attempted,
errors or lost acknowledgements never trigger a second send through PHPMailer.

Reports and attachments still use their existing mailer. Legacy functions remain
inside the compatibility adapters until their callers/configurations are migrated;
this is not removal of PHPMailer or all global state. New source is included in
offline bundles. LTS is unchanged.

## Opt-in row-count cache scheduler

IdentityAccess now owns invalidated permission-count cache cleanup through
`CleanInvalidatedRowCache` and its `InvalidatedRowCache` port. Domain and
application code have no Symfony, PDO or global dependencies. Symfony Scheduler
and Messenger are infrastructure adapters; the installation adapter reads the
primary collector database. This does not schedule polling, reports, RRD work,
log rotation or authentication-token expiry.

The default remains legacy `poller_maintenance.php`. To transfer only this cleanup:

1. Deploy Composer dependencies, run the database upgrade and clear the production
   Symfony container. Fresh schemas and the 1.2.31 upgrade create
   `user_auth_row_cache.class_time (class, time)`. Installations already stamped
   1.2.31 must add this index once before enabling the worker; check `SHOW INDEX
   FROM user_auth_row_cache` first. The worker refuses cleanup if it is absent.
   The additive index is safe to retain when rolling back.
2. Create a writable `var/scheduler` directory for the worker account. Preserve
   that directory across deployments (for example with a local shared-directory
   symlink). It contains schedule checkpoints and locks, not disposable cache.
3. Set `KADUPUL_ROW_CACHE_SCHEDULER=1` in both the primary poller/maintenance
   environment and the supervised worker environment. Only literal `1` enables
   it. Remote collectors always retain legacy cleanup and cannot run this worker.
4. Check the schedule with `php bin/console debug:scheduler row_cache`, then run
   `php bin/console messenger:consume scheduler_row_cache --time-limit=3600 --memory-limit=128M --failure-limit=1` under systemd or another process supervisor configured
   to restart the worker even after a successful time-limit exit. Keep the existing
   poller cron entry; it still owns all other work.

Use one designated host and the same installation/state directory and OS account
for all instances. The filesystem lock excludes concurrent workers sharing that
local directory; it is not a distributed lock across separate hosts or volumes.
The disabled schedule is empty and does not open installation configuration.

The period is five minutes. Persisted checkpoints and
`processOnlyLastMissedRun(true)` avoid replaying every missed interval. Symfony
7.4 may defer a restarted schedule to the next tick, so do not rely on an
immediate catch-up run. Each run deletes at most 1,000 rows per invalidated class;
remaining rows are processed on subsequent runs. It preserves rows at/after the
cutoff, rechecks timestamps before deletion to protect concurrent refreshes, and
is safe to repeat. Failures remain visible to Messenger. `--failure-limit=1` makes the worker exit
on a failed message; its supervisor must restart it so a dropped database
connection is replaced. A later tick retries against current database state. It does not promise exactly-once work.

Monitor supervisor restarts, Messenger failures, and backlog size. If the worker
stops, stale cache rows may accumulate, but the existing read path rejects
invalidated rows and recomputes counts. To roll back, disable automatic worker restarts in the supervisor, stop every
worker sharing this installation and wait for any in-flight cleanup to finish.
Remove the switch from both the worker and primary maintenance environments,
then restore/restart primary maintenance. Do not resume legacy cleanup while a
worker can still run with its original environment. Legacy cleanup resumes on its next maintenance run. LTS is unchanged.

See the [Symfony 7.4 Scheduler documentation](https://symfony.com/doc/7.4/scheduler.html)
for worker supervision, stateful schedules and locking.

### Inspect and run row-cache cleanup

`php bin/console kadupul:maintenance:row-cache` reports the stale row backlog
without deleting anything or creating worker state. Add `--json` for structured
output containing the mode, configured scheduler switch, deleted count, total
remaining rows and per-class cutoff/count. Cutoffs are Unix timestamps. The
switch reports configuration, not proof that a worker is healthy. Counts are
live observations and may change while cache writers are active.

To perform one bounded cleanup manually, stop the scheduled worker and use
`php bin/console kadupul:maintenance:row-cache --execute --json`. It shares the
worker's lock, rejects overlapping ownership before database access, and returns
a nonzero exit status on contention or failure. It works with scheduling disabled
on a primary installation with the required index. It does not coordinate with
the legacy maintenance process; cleanup remains idempotent and rechecks cutoffs.

On failure some classes may already have committed deletions. The command reports
this without exposing database exception details; inspect again before retrying.
A successful command reports remaining rows rather than claiming all cleanup is
finished. Restart the scheduled worker afterward if that is the chosen owner.

## Site creation through Symfony

`/app.php/inventory/sites/new` now renders a Symfony Form in Twig and dispatches
Inventory's `CreateSite` use case. The Sites list links to it. Creation accepts
name, address lines, city/state/postal code/country, timezone, latitude/longitude,
map zoom, alternate name and notes. Domain validation enforces Unicode/database
bounds, known timezones, coordinate ranges and precision, and zoom 0–23. It uses
the same name/notes rules as the migrated editor. Duplicate names remain allowed,
as in the legacy schema; this is not an idempotency guarantee for repeated POSTs.

Console and realm 3 authorization apply to both form access and submission, and
are rechecked by the persistence adapter inside its transaction. Stateless CSRF,
extra-field rejection and fixed-route navigation protect the form boundary.
The site and both legacy invalidation markers (`time_last_change_site` and
`time_last_change_site_device`) commit together. A marker failure rolls back the
site. The adapter does not bootstrap a legacy page or invoke plugin/poller work.

Successful creation redirects with HTTP 303 to the existing Twig site editor.
Validation failures return 422, revoked access 401/403 and uncertain write results
502 with a message to inspect the list before retrying. Responses are private and
not stored. Labels and errors use the English/French Inventory catalogs. Existing
site address/map editing, duplication and deletion remain in `sites.php`; LTS is
unchanged.


## Completed Sites workflow on main

The [25-item Sites completion batch](migrations/inventory-sites-batch-25.md) extends
the earlier name/notes editor to every standard site field, restores the remaining
list sorts, and adds single/bulk duplication and deletion. Symfony confirmation
forms own POST/CSRF validation. Inventory use cases authorize the operation, and
the persistence port locks and checks every selected revision before any write.
Deletion unassigns active devices; duplication copies site settings without devices.
Both cache markers commit with the operation. A failure rolls back the entire batch.
The full-field revision also rejects concurrent address/timezone/map edits.

`sites.php` now only enters Symfony. Old list/edit links redirect, timezone lookup
uses the framework route, and old POST forms are rejected with 409. The procedural
page functions, raw redirects and page-level global declarations are removed.
The earlier sections describe the initial migration slices; their remaining legacy
Sites operations are superseded by this batch. LTS is unchanged.

Online remote collectors retain Sites administration through their existing
`rdatabase_*` configuration. The Sites-only configuration path verifies initialized
local/primary schemas, an empty local recovery queue and primary connectivity.
No collector-local write fallback is allowed, and non-Sites routes and CLI workers
retain the primary-only restriction. Legacy menu loaders navigate to a full Symfony
document instead of inserting it as an AJAX fragment, retaining unsaved-form prompts.
### Device creation

`/inventory/devices/new` now uses a Symfony Form and Twig page backed by the
Inventory `CreateDevice` use case. `PrepareDeviceCreation` reads non-secret
installation defaults and current template, site and enabled-poller choices
through a Doctrine DBAL adapter on the Inventory read connection.
Domain validation rejects unknown fields, invalid references at the form boundary,
unsupported protocols, out-of-range values and invalid SNMPv3 combinations.
The isolated legacy adapter rechecks the actor, realms and selected references
before calling `api_device_save` with an enforced zero ID. This preserves template
associations, poller/cache behavior, automation and plugin save/create hooks.

Configured community strings and passphrases are resolved only in the CLI worker;
they never populate the HTTP form. Explicit credentials use password fields and
are cleared on validation failures. Clear **Use configured credentials** before
entering device-specific secrets. The default enables polling; operators can
choose Disabled before creating devices that should not be polled yet.

Creation uses stateless CSRF protection, private/no-store responses and a 303
redirect to the permission-filtered device list. Creating a device does not grant
visibility permissions. Worker failures and timeouts have an uncertain outcome:
check the list before retrying. The adapter never retries automatically. A local
transaction protects database work where possible, but plugin, remote-poller,
SNMP and filesystem effects are not a distributed transaction. Duplicate names
and repeated valid submissions retain legacy behavior; creation is not an
idempotent API. Custom plugin form fields and subsequent graph/query management
remain on the legacy pages; the standard creation fields and core hooks are
covered here. Existing legacy URLs and LTS remain unchanged.

Device creation honors the configured `path_php_binary` executable (falling back
to the current PHP installation) and legacy defaults when settings are absent.
The HTTP catalog never reads the stored SNMPv3 username. With configured
credentials selected, a blank username is resolved in the authorized CLI worker
and validated before persistence; an explicitly entered username is retained.
The worker locks the account, authentication/guest policy and whichever direct
or group grants authorize the transaction. Remote collectors must be online:
the worker prepares both connections for full UTF-8 and strict writes and checks
that the collector received the same device fields before confirming creation.
Before commit, required template graph/query associations must exist on the
primary, and collector graph/query associations (including reindex methods) must
match the primary. Verification failures do not report successful creation.
Replication failure remains an uncertain outcome because remote writes cannot
be rolled back with the primary transaction.

The creation-only save guard rejects plugin changes to the device ID or the
authorized template/site/collector before persistence. The worker suppresses raw
SQL debug output and replaces database log payloads with a fixed diagnostic;
the actor/device audit entry remains available. Plugins are trusted server code
and remain responsible for their own direct file or external logging. The default
CLI filename is platform-specific (`php.exe` on Windows).

### Device site reassignment

The Symfony device editor now includes site assignment and explicit Unassigned.
`ListAssignableSites` authorizes the choices query through a dedicated Inventory
port; site administrators can select any current site, while device visibility
still controls which devices they may edit. Its infrastructure adapter uses a
module-owned Doctrine DBAL connection;
the application query and returned site map remain unchanged. The connection
retains the installation's TLS certificate verification, UTF-8 and native
prepare settings. The device-site filter, device details and site catalog reads
share that connection. The device list, which is the remaining
permission-filtered read, moves after its search rewrite; it and all write
transactions keep their existing PDO path until migrated and covered
independently.

An operator can give that connection its own MySQL user with SELECT only, so
the database itself rejects writes through it. Set both
`$database_read_username` and `$database_read_password` in
`include/config.php`. Setting only one of them stops the connection with
`Incomplete read-only database credentials.` rather than guessing. With neither
set, the connection keeps using `$database_username` and `$database_password`
as before; that fallback grants nothing new, and the read-only protection
starts only once the read user is configured. A remote collector ignores both
settings and keeps the `$rdatabase_*` primary credentials on Sites routes.

Grant the read user only the tables the DBAL adapters read, including the
permission tables that device visibility reads. The list grows as more adapters
move to DBAL:

```sql
CREATE USER 'kadupul_read'@'localhost' IDENTIFIED BY 'change-me';
GRANT SELECT ON cacti.sites TO 'kadupul_read'@'localhost';
GRANT SELECT ON cacti.settings TO 'kadupul_read'@'localhost';
GRANT SELECT (id, description, hostname, disabled, status, location, external_id,
    notes, site_id, deleted) ON cacti.host TO 'kadupul_read'@'localhost';
GRANT SELECT ON cacti.host_template TO 'kadupul_read'@'localhost';
GRANT SELECT ON cacti.poller TO 'kadupul_read'@'localhost';
GRANT SELECT ON cacti.graph_local TO 'kadupul_read'@'localhost';
GRANT SELECT (id, policy_graphs, policy_hosts, policy_graph_templates)
    ON cacti.user_auth TO 'kadupul_read'@'localhost';
GRANT SELECT ON cacti.user_auth_perms TO 'kadupul_read'@'localhost';
GRANT SELECT (id, enabled, policy_graphs, policy_hosts, policy_graph_templates)
    ON cacti.user_auth_group TO 'kadupul_read'@'localhost';
GRANT SELECT ON cacti.user_auth_group_members TO 'kadupul_read'@'localhost';
GRANT SELECT ON cacti.user_auth_group_perms TO 'kadupul_read'@'localhost';
```

Replace `cacti` with `$database_default` and the host with the web server's
address.
The column lists keep password hashes, account fields and per-device SNMP
credentials away from the read user. `settings` cannot be limited by row and
still holds the default SNMP credentials, so protect the read user's password
as closely as the primary one. Extend a column list when an adapter selects a
new column.
The domain revision includes site ID,
so an assignment changed in another editor invalidates stale forms. Missing or
invalid submitted choices cannot silently unassign a device.

The isolated worker locks source and target sites in ascending ID order before
the host, rechecks the source association and revision, and preserves the legacy
save/plugin path. A save hook cannot replace the locked target site. Assignment
changes invalidate device and site-device cache markers within the transaction.
A missing historical source site can be repaired by selecting a current site or
Unassigned. Timeouts and remote/plugin effects retain the existing uncertain-save
behavior; the adapter never automatically retries.

This completes site assignment in the editor; template, collector and detailed
polling/credential editing remain separate migration slices. LTS is unchanged.

### Device polling settings

The Symfony device editor includes device threads, SNMP port and timeout, maximum
OID count, bulk-walk size, availability method, and ping method/port/timeout/retries.
`DevicePolling` validates a complete set of settings and is shared by device
creation and editing. All fields participate in the device revision; an external
change rejects stale forms before persistence. Missing or invalid settings do not
silently reset existing values. Legacy ping method `0`, `max_oids=0`, `snmp_port=0` and `ping_timeout=0`
remain selectable and round-trip unchanged. The SNMP sanitizer resolves port zero
to 161; the ping engine resolves timeout zero to 500 ms. OID zero uses the
configured maximum.
NULL ping methods and other unsupported historical values require an explicit
valid choice before saving; they are not silently normalized. The worker repeats validation and retains the
legacy cache/poller/plugin path. SNMP credential and protocol editing, template
and collector assignment remain separate slices. LTS is unchanged.


### Device SNMP editing

The Symfony device editor submits SNMP public settings and an explicit preserve
or replace credential choice through the Inventory EditDevice use case. Domain
validation runs before mutation and again inside the isolated legacy worker.
Stored community, username and passphrases are read only inside that worker;
the HTTP adapter never selects them. Password fields remain empty on errors.
Database diagnostics are redacted before the worker bootstraps legacy code.

Public SNMP configuration participates in stale-form detection. Credential-only
rotation does not: preserving credentials resolves the latest stored values
under the host lock; explicit replacement intentionally replaces them. Two
explicit credential replacements use last-write-wins semantics. No secret or
secret-derived fingerprint is exposed through the revision token.

Leaving SNMPv3 clears v3 credentials, protocols, context and engine ID through
the existing legacy API. Stored credentials incompatible with selected v3
protocols produce a validation error and roll back the entire edit.


### Device template assignment

The device editor links to a dedicated Symfony Form/Twig workflow backed by
AssignDeviceTemplate, a template-assignment aggregate, and a persistence port.
Template and collector identities participate in stale-form detection. Choices
are loaded through an authorized query; the worker locks policy/grants and
rechecks visibility, references and the revision before writing. Creation shares
the same authorization-lock helper. Template writes use a repeatable-read
transaction and current locking reads for visibility mode, user/group policies,
memberships, and permission exceptions, including gaps where new exceptions could
revoke access. The final device/graph visibility query is also a locking read.
The isolated worker uses the configured PHP executable, with a platform-correct
fallback on Windows.

Changing a template delegates graph/query attachment and unused graph-association
cleanup to the legacy API. Existing graphs and data remain intact. Unassignment
keeps existing associations. Unchanged selections do not resynchronize templates.
Required associations and final template identities are verified before success.

Assigned remote collectors must be available for a change. Remote writes are not
a distributed transaction: a collector can receive changes before a later failure
rolls back the primary. Such failures return an uncertain-outcome error; operators
must reload and verify before retrying. Collector reassignment is a separate
workflow and is not accepted by this form.

### Device collector assignment

The device editor links to a dedicated Symfony Form/Twig collector page backed by
AssignDeviceCollector, a collector-assignment aggregate and a persistence port.
Collector and template identity participate in stale-form detection. The worker
rechecks account/realm and visibility permissions with current locked reads,
locks the device and referenced collectors, and only accepts an enabled target.
Both remote collectors must be online for a move; unchanged selections are no-ops.

The worker uses legacy replication and purge hooks, verifies the target's device,
polling, graph and data-source configuration before cleaning the source, and
checks source cleanup before reporting success. It includes host-graph
associations omitted by the legacy bulk transfer helper. Returning a device to a
collector cancels obsolete queued purge commands for that target. Polling item
ownership, collector statistics and both device-cache markers are updated.
Existing graph/data identities and collected data files are retained.

This is not a distributed transaction or a durable transfer/recovery scheduler.
A failure can leave changes on a remote collector while the primary transaction
rolls back. The UI reports an uncertain outcome and requires reload/verification
before retrying; a successful response is only sent after verification and
primary commit. Offline/deferred moves and collector administration remain
outside this workflow.


### Bulk device enable/disable

The Inventory list now selects up to 100 devices for a Symfony Form/Twig
confirmation at `/inventory/devices/enable` or `/inventory/devices/disable`.
`PrepareDeviceStateChange` and `SetDevicesEnabled` use the `DeviceStates` port;
`DeviceSelection` and `DeviceState` keep selection and revision rules independent
of Symfony and the legacy database. GET is read-only, POST requires stateless
CSRF, and list filters survive confirmation and redirect.

The isolated adapter worker locks authorization, sites, hosts and collectors,
checks current visibility and every expected revision before any writes, and
preflights online remote collectors. Primary writes share one transaction.
Disabling resets primary status; enabling retains populated poller caches and
uses the legacy rebuild/reindex functions for empty caches. The legacy
`device_action_bottom` hook receives the action and full selection. Already
matching states are no-ops and do not rebuild caches or invoke the hook.

During writes the worker opts into immediate exceptions from the legacy SQL
helper, so swallowed cache errors or a deadlock cannot continue later statements
outside the authorization transaction. Other legacy callers retain their error
return behavior. Final primary and remote states are verified before success;
remote effects are not a distributed transaction and may survive primary
rollback. HTTP 502 explicitly asks operators to inspect every selected device
before retrying. Logs contain actor, requested state and IDs, never credentials.


### Device removal and retention

`/inventory/devices/remove` uses a Symfony Form/Twig confirmation backed by
`PrepareDeviceRemoval`, `RemoveDevices` and the `DeviceRemovals` port.
`DeviceRemoval` revisions include graph and data-source IDs as well as device
identity and assignments. The isolated worker rechecks the complete selection,
visibility, authorization, revisions and online collector identities under locks
before writing. GET does not mutate; POST requires CSRF and an explicit policy.

Retain leaves graphs and disabled data sources unassigned. Purge invokes the
legacy graph/data lifecycle and also handles data sources with no graph. It
rejects shared data references and aggregate memberships that would affect
objects outside the selected devices; detach those in their owning modules or
choose retention. Legacy removal and bulk-action plugin hooks are retained.
RRD cleanup follows the existing configured maintenance queue, not the HTTP
request. Remote child configuration is cleaned before its ownership rows vanish.

Primary writes are transactional, with immediate SQL failure handling. Remote
and plugin effects may survive rollback and failures return an explicit uncertain
outcome. Primary local devices disappear; remote devices retain the legacy
cleanup tombstone until maintenance purges it. This is not a restore facility.
LTS is unchanged.


## Command-line tools

`php bin/console kadupul:database:analyze` recalculates index cardinality for
every table. On a remote collector it analyzes the main database unless
`--local` is given. It acts as the account named by `--as`, or the
`admin_user` setting, which must be enabled, unlocked and hold the Console
Access and Settings/Utilities realms. An empty `--as=` is rejected with exit
2. `--json` prints one object with `status`, `database`, `binlog_enabled` and
`tables`. The operator is checked against the database the command analyzes.
Collectors hold a replicated copy of the accounts, so on a remote collector
`--local` works without reaching the main database.

`cli/analyze_database.php` still works with its old flags. It now
forwards to the command and prints a deprecation note on stderr; set
`KADUPUL_CLI_QUIET_DEPRECATION=1` to silence it in cron.

Known differences from the original script:

- It needs an operator: the `--as` account or `admin_user` (user 1 when that
  setting is absent), with the Console Access and Settings/Utilities realms.
  The original ran for anyone who could run it.
- An invalid flag prints the error and help without the version line, because
  the shim rejects the flag before it boots the kernel.
- Any database fault prints the generic `ERROR: Database analysis failed`.
  Only a missing main configuration is named.
- Output is printed after all tables finish, not as each one completes.
- With `include/cacti_version` missing, the original printed
  `ERROR: failed to find cacti version file` and exited 0 on every path. The
  command still analyzes; `--version` and `--help` print
  `ERROR: Database analysis failed` and exit 1.
- On a primary installation, the command opens a separate connection for the
  main database, with the same credentials as the local one.

`php bin/console kadupul:database:convert-tables` converts installation
tables to InnoDB (`--innodb`), to `utf8mb4_unicode_ci` (`--utf8`) or to
latin1 (`--latin1`), with the old `--table`, `--skip-innodb` (space
separated), `--size`, `--rebuild`, `--dynamic`, `--force` and `--local`
options. It needs the Console Access and Installation/Upgrades realms, the
realm the install wizard's table conversion requires. `--dry-run` lists the
statements without running them. `--json` prints `status`, `database`,
`dry_run` and `tables`, each with `name`, `result` (`converted`, `failed`,
`planned`, `skipped` or `too_large`), `rows` and, when one was built,
`statement`. The installer converts the tables it queued in-process, with
no operator, as the web install wizard's own step. Operators who run the
command or `cli/convert_tables.php` from a shell still need realm 26.

Known differences from `cli/convert_tables.php`:

- It needs an operator with the Console Access and Installation/Upgrades
  realms, checked on the database it converts. The original ran for anyone
  who could run it. An admin who has not yet changed the initial password is
  refused from a shell; the installer's in-process conversion needs no
  operator.
- While no user and no enabled group with members holds Installation/Upgrades,
  an operator with a direct Settings/Utilities grant may run it, as
  `include/auth.php` allows on the web. The web path then writes a realm 26
  row for every such user; the command allows only the current run and
  writes no row. It stays stricter than the web path: the operator still
  needs Console Access and must not have a password change pending.
- An invalid flag prints the error and help without the version line.
- `--installer` is rejected as an invalid parameter. The original required a
  path without its separator and died with a PHP fatal error, exit 255.
- `--size` must be a whole number, and `--size=`, `--table=` and
  `--skip-innodb=` with no value are invalid parameters.
- `--size=abc`, or any size that is not a whole number, prints
  `ERROR: Invalid Parameter` and the help and exits 1. The original accepted
  it, exited 0, and compared row counts with the text, which in PHP 8 let
  every table through.
- A table name that does not exactly match (letter case included) a table the
  server lists is reported as `Failed` and logged, without sending `ALTER TABLE`. The original sent the
  statement, logged the server's error and printed PHP warnings.
- Skip-table names must match a table exactly; the original matched them with
  `LIKE`.
- `--dynamic` on a table that needs only the row format change runs
  `ROW_FORMAT=Dynamic`. The original built a statement with a trailing comma,
  which failed.
- With `--innodb`, the command stops with `InnoDB Engine is not enabled` when
  the server reports InnoDB as `NO` or `DISABLED`, or does not list it. The
  original compared against `off`, which no server reports.
- On a remote collector the command reads the main database's own tables. The
  original looked them up under the local database's name.
- A failed statement logs the server's message, and at debug verbosity the
  line with its error number and statement, whose text differs in quoting and
  spacing; there is no backtrace.
- Output is printed after every table is done.
- On a primary installation the main connection is a second connection with
  the same credentials, as for `kadupul:database:analyze`.
- On a remote collector the installer converts the collector's local
  database, the one its queue describes. The original script converted main.

`php bin/console kadupul:database:widen-id-columns` widens the id columns
that `cli/fix_mediumint.php` widened to `int(10) unsigned`. It needs the
Console Access and Installation/Upgrades realms, the realm of the 1.2.17
upgrade step that runs the same change. `--local`, `--debug`, `--as`,
`--dry-run` and `--json` behave as for the convert command; JSON has
`status`, `database`, `dry_run`, `adjusted` and `tables`.

Known differences from `cli/fix_mediumint.php`:

- It needs an operator with the Console Access and Installation/Upgrades
  realms, with the same fallback to direct Settings/Utilities holders while
  nobody holds Installation/Upgrades.
- Every table outside the named list gets its own statement, as in
  `install/upgrades/1_2_17.php`. The original appended those columns to the
  last named table's statement, which then failed, and counted them per
  column; the command counts tables.
  With `--debug` such a table prints one `Updating Table` line, where the
  original printed one per column.
- Only integer columns narrower than `int unsigned` are changed. The original
  also rewrote `bigint` columns, which narrowed them, and non-integer columns.
  MySQL 8's `int unsigned`, printed without a display width, counts as
  already converted.
- A nullable column with a default stays nullable. The original made it
  `NOT NULL`.
- Tables and columns match by exact name, letter case included, as the
  server's `information_schema` lists them; the original used `LIKE` for
  columns. Names are checked again just before each `ALTER TABLE`, and a name
  the server no longer lists fails without a statement being sent.
- Only base tables are read. The original also walked views, whose
  `ALTER TABLE` failed. MariaDB system-versioned tables are not base tables
  either, so this command and `kadupul:database:convert-tables` skip them,
  where both originals included them.
- Generated and invisible columns are skipped (`EXTRA` containing
  `GENERATED` or `INVISIBLE`, which on MySQL 8 includes an expression default
  marked `DEFAULT_GENERATED`); `--debug` reports each one. `MODIFY` cannot
  give a generated column a plain type and would drop `INVISIBLE`. The
  original rewrote both.
- A column is widened only if it is still exactly as the command read it,
  type, nullability, default and `EXTRA` included, just before its
  `ALTER TABLE`; otherwise that table fails without a statement being sent.
- As in the original, a signed column becomes unsigned, and strict SQL mode
  refuses the statement when the column holds a negative value. `MODIFY`
  also drops a column `COMMENT`, as the original's did. On MariaDB an
  expression default is sent as a quoted literal, which the server refuses;
  the table is reported as failed and audited.
- JSON `adjusted` counts every table a statement was sent or planned for,
  failed ones included, as the legacy `Column widths adjusted` line does.
- On a remote collector the command reads the main database's own table list.
- A failed statement logs the server's message, and at debug verbosity the
  line with its error number and statement; there is no backtrace.
- An invalid flag prints the error and help without the version line.

`php bin/console kadupul:database:audit` compares the schema with
`docs/audit_schema.sql` (`--report`), prints the statements that would make
them match (`--alters`), runs them (`--repair`), reloads the audit tables
(`--create`), or rewrites the file from this database for developers
(`--load`). `--upgrade` first runs `cli/upgrade_database.php` and the plugin
upgrades when the database version is behind the code. It needs the Console
Access and Installation/Upgrades realms, the realm of the install wizard,
which is the only place the web UI changes the core schema
(`include/global_arrays.php:1284-1285`). `--dry-run` reads the file and the
schema and changes nothing, not even the two audit tables. `--json` prints
`status`, `database`, `dry_run`, `mode`, `upgrade`, `baseline`, `tables`,
`alters`, `imported` and `exported`.

Known differences from `cli/audit_database.php`:

- It needs an operator with the Console Access and Installation/Upgrades
  realms, with the same Settings/Utilities fallback as convert-tables. The
  original ran for anyone who could run it.
- `docs/audit_schema.sql` is parsed and its rows inserted with bound values.
  The original piped the file into the `mysql` client with the password on
  its command line. `FATAL: mysql or mariadb command not found` no longer
  occurs, and a file with an `INSERT` into any other table, a row with
  several value lists, or a value that is not a string, number or `NULL` is
  refused whole: `FATAL: Failed Load the Audit Schema` is followed by
  `ERROR: docs/audit_schema.sql line <n> does not parse` instead of the
  client's output, and the client's error, which the original let through
  to stderr, is not printed. The two audit tables get the definitions in
  the file, from constants that a test keeps equal to it.
- Only base tables are audited and imported; the original also walked views.
  Table names are quoted in `SHOW COLUMNS` and `SHOW INDEXES`.
- A repair statement is built from typed parts: names quoted, defaults as
  quoted literals, `FIRST` in capitals, one line. `--alters` and a failed
  repair print the original's statement text. A clause with no typed form
  (an `EXTRA` other than `auto_increment` or `on update CURRENT_TIMESTAMP`, a
  type outside the list MariaDB prints, an index with no `USING`, a table
  character set outside the list, a baseline name outside the pattern) makes
  its table `Failed` without a statement being sent; the server refused each
  of these when the original sent it, so only the log differs: no `DBCALL`
  lines.
- A default is written as a literal with its backslashes doubled, as the
  widen command writes it. Under `NO_BACKSLASH_ESCAPES` the server keeps
  both, so a default of `x\` is stored as `x\\`.
- A `plugin_db_changes` table without the `table`, `column` and `method`
  columns makes the audit fail. The original's lookups failed quietly and
  matched nothing, so every plugin table and column counted as unknown.
- Each table is read again just before its `ALTER TABLE`; a table that
  changed since the audit read it, or a statement that modifies or drops a
  column or index the server does not list by that exact name, fails
  without a statement being sent.
- A `MODIFY COLUMN` names the column as the server lists it. The original
  used the audit schema's spelling, which it matched without letter case, so
  a column whose case differs is now named in its live case. MariaDB 11.8
  renames a column to the letter case a `MODIFY COLUMN` spells it in, so the
  original's repair also renamed such a column to the audit schema's case;
  the command keeps the live name and changes only the definition.
- A column that is `NOT NULL` with no default in the audit schema, and whose
  `Extra` has drifted, is modified with `DEFAULT '1'`, as the original did:
  its comparison turns the missing default into `true`, which prints as `1`.
  No row in the shipped `docs/audit_schema.sql` reaches this case, but a file
  rewritten by `--load` from another database can.
- A failed import under `--load` makes the run fail. The original printed
  `Importing Table: ... - Done` for every table either way and exited 0.
- `--dry-run --load` lists the two audit tables even when they do not exist
  yet, since an applied run creates them before it lists the schema.
- `--load` reads every table before it imports any, so the rows it records
  for the indexes of `table_columns` and `table_indexes` themselves carry
  the cardinality of the empty tables. The original read each table as it
  reached it, after importing the tables before it, so those two tables'
  cardinality counted rows it had just inserted. Cardinality is the
  server's estimate, and the audit never compares it.
- `--upgrade` runs `cli/upgrade_database.php` and each plugin's
  `database_upgrade.php` through Symfony Process with an argument array and
  the PHP binary the command runs under, not `php` from `PATH` through a
  shell; a plugin's recorded version reaches its script as one argument. The
  upgrade's stderr is printed after it finishes, and all output appears when
  the run ends.
- An upgrade whose `cli/upgrade_database.php` exits non-zero fails the run.
  The plugin upgrades still run and their output is the original's, but no
  mode runs after them. The shim then prints `FATAL: Kadupul Upgrade Failed.
  The audit was not run.` and exits 1. Under `--json` the status is
  `failed`, with `upgrade: failed`. The original printed the errors and went
  on, so `--upgrade --repair` sent its `ALTER TABLE` statements to a half
  upgraded schema.
- An upgrade that stops before its end, on an exception or a crash, fails
  the run the same way, and the exception's text is not printed. The
  original died there with the PHP error, so no mode ran either.
- `--load` writes `docs/audit_schema.sql` only when the dump program
  succeeds. The original truncated the file first.
- A failed export prints the original's `Finished Creating Audit Schema
  with ERROR` and exits 0; under `--json` it reads `partial`, with
  `exported: false`, and exits 1. A dump program that exits non-zero logs
  the original's `DBCALL ERROR: mysqldump failed with exit code <n> for
  database '<db>'`. The dump is stopped after 300 seconds, where the
  original waited for it, and logs `DBCALL ERROR: mysqldump timed out after
  300 seconds for database '<db>'`. A dump that cannot be written to
  `docs/` logs `DBCALL ERROR: could not write the audit schema dump for
  database '<db>'`. Neither of the last two lines names the command.
- `--load` dumps from the configured database server: the dump program
  gets the host and port from `include/config.php`, and the TLS CA,
  certificate and key when the connection uses TLS. Its environment holds
  only `PATH`, `HOME` and the password, so `MYSQL_HOST`, `MYSQL_TCP_PORT` or
  `MYSQL_UNIX_PORT` cannot point it elsewhere. The original named neither
  host nor port, so the client used its own default.
- A failed statement, such as an `ALTER TABLE` the server refuses or the
  `CREATE TABLE` of an audit table, logs the server's message as the
  original did, but no `CMDPHP SQL Backtrace` line follows it, as with the
  other write commands.
- Any database fault prints the generic `ERROR: Database audit failed`.
- `--dry-run` exists only under `bin/console`; there it runs no statement,
  including the audit tables' reload, which every original mode ran.
- The shim refuses `--dry-run`, `--json`, a bare or empty `--as`, and
  `--as NAME` with a space, before it loads anything: it prints `ERROR:
  Invalid Parameter <flag>`, a blank line and the help, and exits 1. The
  original ignored all of them and ran, so `--repair --dry-run` ran a real
  repair. `--as` takes its value only as `--as=NAME`, as in the other
  shims.
- `--as=NAME` with no mode checks the operator before it prints the help,
  so an unknown or unauthorized name is refused with `ERROR: Unknown or
  unauthorized operator` and exit 1. The original ignored `--as` and printed
  the help.
