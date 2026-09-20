# Symfony migration on main

This work is limited to `main`. The runtime floor, dependency distribution and
application structure on `lts/1.2` are unchanged.

The target is [DDD modules with hexagonal internals](architecture.md), composed by Symfony.

## Foundation

Main requires PHP 8.2 or later. `mise.toml` selects PHP 8.3.33 for development,
Node 22.22.2 for asset builds and Python 3.12.12 for the behavioral harness and
release builder. CI checks the application on PHP 8.2, 8.3 and 8.4.

Symfony 7.4 owns a new kernel, service container, attribute routes, console and
Twig configuration. Its only HTTP endpoint is `GET /healthz` (also supporting
HEAD). This is a liveness check, not a database, collection or storage readiness
check. Other paths return 404. Sessions are disabled in this foundation.

Existing pages and scheduled commands still use the legacy bootstrap. Do not
switch an existing installation's document root to `public/` yet: legacy route
coexistence and authentication bridging are subsequent milestones.

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

The security inventories exclude generated Symfony cache (`var/`) and npm
packages. The four new reviewed inventory entries are CLI-only build/verification
operations: a fixed bootstrap under the current release directory, a pinned HTTPS
archive download, its temporary file, and checksum-verified writes to allowlisted
legacy dependency paths. No HTTP request data controls these operations.

Additional validation: Symfony kernel/console/Twig tests pass (4 tests, 22
assertions), asset patch tests pass (19 tests), RSA compatibility passes, mailer
compatibility passes (16 tests), HTML Purifier/runtime-floor checks pass (2 tests),
and CSV paths pass (4 tests). Offline verification succeeded on PHP 8.2 with
Docker networking disabled. The standalone `CsrfHardeningTest` retains one
pre-existing source-text assertion failure: it expects a literal `'delete'` in
unchanged `include/global.php`; its other three checks pass.

The production Docker image builds successfully. An initial attempt exhausted
the local Docker build filesystem; a subsequent complete build passed. The
application harness image and disconnected archive verification also completed.
