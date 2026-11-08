<!-- SPDX-FileCopyrightText: 2026 The Kadupul project and contributors -->
<!-- SPDX-License-Identifier: GPL-3.0-or-later -->

# Symfony Security authentication migration

Status: request authentication for three routes, login/logout presentation,
shared Logout Everywhere persistence, password verification/complexity, local
credential/policy coordination and the native account-lockout gate are implemented
locally on a branch now fast-forwarded to `origin/main` at `988353eaf5`. The
authentication work began from `44ef2204ec`. The remaining stages below are
planned. This implements part of [ADR 0010](../adr/0010-symfony-security-authentication.md).
LTS is unchanged. Nothing in this record claims deployment or release.

## Consolidated local authentication acceptance

The current local workflow is executable end to end through the retained native
entry points and the new Symfony services. This is an integrated migration stage,
not retirement of the native authentication implementation. The application
commands confirm credentials, apply local password policy, record lockout
failures and coordinate the post-authentication transition. The native facade
still owns plugin dispatch, PHP session issuance, password-change orchestration
and redirect selection. There is one active write path for each operation. The
Symfony firewall consumes the resulting native session and revalidates current
identity and policy.

The installed HTTP matrix uses PHP 8.4.27, the committed
dependency lock, the shipped SQL schema and the real CLI installer, which reports
schema version 1.2.38. Each isolated installation lives under `/cacti/`; polling
and automation are disabled. Both native session backends pass:

| Database version observed | File-session checks | Database-session checks |
| --- | ---: | ---: |
| MySQL 8.4.13 | 127 | 129 |
| MariaDB 10.11.19 | 127 | 129 |
| MariaDB 11.8.9 | 127 | 129 |

These 768 checks cover the rendered login/CSRF handoff, rejected and admitted
credentials, session ID rotation, lockout and timed recovery, legacy-hash upgrade,
ordinary and forced password changes, remembered-token restoration/rotation,
POST logout, subsequent token refusal, realm isolation, current account/group
policy, HTTPS policy, disabled and locked guest behavior, guest session rotation,
guest console refusal and account deletion. Each run uses real HTTP, SQL and
native session storage. Test settings are restored and fixture accounts are
removed. `local_authentication_scenarios.py` is registered in session-bridge
shard 3/3 for both backends and in the HTTP coverage source manifest; the shard
registration suite passes 13 cases.

Apache Basic authentication now has a separate HTTP scenario in the same
session-bridge shard. It configures a temporary Apache Basic provider, proves a
bad server password is refused before application session creation, and verifies
that an Apache-authenticated realm-2 account gets a rotated native session
accepted by the Symfony firewall. The local Docker runs used cached public
Apache/PHP and MariaDB images after the pinned private GHCR images
returned unauthorized; CI remains the authoritative run with the pinned images.

On 2026-11-08, after fast-forwarding to `988353eaf5`, both installed HTTP
scenarios passed on MySQL 8.4.13 and MariaDB 10.11.19/11.8.9 with both session
backends: 768 checks across six reports. Each report covers 2,621 source files
with zero hash mismatches against the integrated checkout. MySQL installation
used the test-server `log_bin_trust_function_creators` setting because binary
logging otherwise refused the installer-owned readiness procedure for the scoped
account (error 1419). Production privilege/configuration requirements remain an
operator/deployment gate. The MySQL runner uses its native client binary; these
local results do not certify the pinned private CI image or an unconfigured
production server.

On the integrated worktree, the full Symfony suite passes on PHP 8.4.25 with
temporary PCOV enabled: 6,149 tests, 335,939 assertions, 510 skipped, zero
failures or errors. The temporary PCOV build and configuration are outside the
repository. `composer validate`, Symfony container/YAML lint, and
`git diff --check` pass. The audit special-mode test skips when the host temporary
filesystem cannot preserve those permission bits. These local results do not
substitute for hosted current-head CI or the full supported database matrix. The
first MySQL attempt stopped at the installer gate; rerunning with the test-server
setting allowed installation and authentication scenarios to complete. The pinned
private GHCR images remain unavailable locally.

The shared post-authentication transition is covered by six Symfony unit cases
and the four GHSA-273r regressions. Those regressions caught and fixed a form
login ordering issue: `sess_user_id` is now assigned only after account
eligibility and the transition succeed. The application decision is wired to
native adapters for account lookup, PHP session rotation/cache clearing, binding
and legacy audit messages. Installed runs on the transition revision completed
for MySQL 8.4.13 and MariaDB 10.11.19/11.8.9 with both session backends; the
latest source-bound reports are in the task's temporary evidence directory.

Review of the transition failure path found that PHP's session ID rotation
result was ignored and the form flow published a remember-me token before the
transition admitted the account. The native adapter now returns the rotation
result; a failed rotation refuses authentication before permission-cache
clearing or credential binding. Form login publishes the remembered credential
only after successful session binding. Regression coverage races a lock between
the initial login checks and the transition and asserts that no remember-me
credential is written or returned. On the current working-tree source, PHP
8.4.25 runs pass `TransitionAuthenticationSessionTest` (6 tests, 16 assertions)
and `AuthControllerNativeCoverageTest` (43 tests, 456 assertions). A combined
run passes 49 tests and 472 assertions; that earlier invocation had no local
coverage driver, so it is behavioral test evidence only. The current worktree has
since passed both installed session backends on MariaDB 10.11.19, as recorded
above. The full current-worktree matrix has also passed on MySQL 8.4.13 and
MariaDB 10.11.19/11.8.9 with both session backends: 768 checks across six
reports, each with zero source-hash mismatches against the checkout. This closes
the local installed-matrix gap for those versions; hosted current-head CI remains
required.

The installed checks exposed an input-boundary regression, now corrected before
credential processing. Malformed credential fields and invalid realm selectors
return an empty HTTP 400 response before authentication side effects. Ordinary
missing/empty credentials retain their login-validation behavior. Five new native
controller regressions cover this boundary; domain dispatch tests also assert the
response status and absence of dispatch. This is an intentional malformed-input
response change on the migration branch. LTS was not modified or certified by
these runs.

The unchanged Logout Everywhere database contract passes 23 cases / 287 assertions
on each of the three database versions, with PHP 8.4.25 and no skips. Those
contract runs precede the final HTTP input guard and cover the identical revocation
service/test sources. The selected Symfony suite discovers 328 cases / 215,650
assertions, with 34 database skips in that invocation. The focused native
controller/cookie run passes 108 cases / 1,270 assertions with PCOV. All nine
Chromium login-presentation and automatic-logout checks pass against an isolated
server with the shipped JavaScript; their intercepted responses are separate from
the real HTTP/database matrix. Domain dispatch passes 40 cases / 173 assertions.

The earlier full native authentication suite discovered 2,626 cases with 65,322
assertions and zero failures/errors: 2,601 clean passes, 24 warning-bearing cases
and one database skip. The unchanged warnings are the localhost startup probe
and spike-removal fixture cleanup. The earlier run with three stale domain-handler
expectations is retained; those tests now assert HTTP 400 and no dispatch, and
the complete suite has been rerun. No warning or skipped case is counted as a
clean pass.

PHPStan, Deptrac, Symfony container lint, changed-file PHP style, Composer
validation and dependency analysis pass. Dependency analysis uses the cached
PHP 8.4.27 image with LDAP enabled and scans 2,062 files. Gettext verification
passes 1,558 source files, 4,301 current identities, 195 historical compatibility
identities and all 23 PO/MO pairs. Catalog reference locations are regenerated;
message identities, translations and compiled MO contents are unchanged. The
reviewed sink inventory passes. The architectural-hotspot check still reports
the pre-existing Windows command in `lib/ping.php:181`; that file and its baseline
are unchanged. This failure remains visible rather than being waived.

A PHP 8.5 Composer-launcher run and a subsequent shared-cache PHPStan run
reported incompatible tokenizer constants in unchanged installer code. The final
PHP 8.4.25 run uses an isolated cache and passes without source changes or
analysis suppressions; those failed attempts remain in the evidence record.

Earlier local evidence is indexed by `kadupul-local-auth-consolidated-source-snapshot.json`
in the task's temporary artifact directory. It binds source/dependency hashes,
runtime/database image identities and reports. Earlier snapshots and failed
attempts remain historical evidence, including the initial input-boundary failure
and the stale shared browser-server attempt. The final browser run disallows
server reuse. Source snapshots below describe their own earlier stage, not the
entire current tree.

### Review boundaries and remaining gates

The additional local review pass inspected the request firewall, native login
credential checks, lockout adapters, logout confirmation flow, and persisted
credential-generation handling. Focused local and installed checks passed as
listed above. This is not independent review and is not a substitute for hosted
CI.

The worktree is now fast-forwarded to `origin/main` at `988353eaf5`. Generated
gettext conflicts were resolved by combining the catalogs and rebuilding them
from the integrated source; a semantic comparison found no conflicting
translation edits. The catalog verifier passes for 1,575 PHP sources, 4,301
static messages, 195 compatibility identities, and all 23 PO/MO pairs. No
publication or deployment has occurred.

Remaining review should independently compare the request firewall and
provenance, shared credential/password-policy/lockout services and native callers,
presentation and POST logout, and installed acceptance registration against the
native baseline, schema and consumers. Keep each behavior with its tests and
configuration. This local review is not independent approval. No push, PR,
deployment or LTS backport is included.

The installed matrix does not establish production FPM/Apache behavior, external
LDAP or plugin acceptance, collector propagation, production concurrency or
performance, or every database target in
[the compatibility matrix](../testing/database-compatibility.md). MariaDB 10.6,
MySQL 8.0 and MySQL 9.7 were not executed locally; their images are not present
in the local cache, and no dependencies were downloaded. Locale preference
selection and missing-bootstrap adapter exception coverage remain incomplete. Current-head
hosted CI, independent review, the existing dependency-advisory disposition and
the unrelated hotspot failure remain release gates. Session issuance and
password-change orchestration remain migration boundaries; they must retain the
verified single-write and revocation contracts.

Roll out or roll back the complete source/configuration/dependency bundle using
the procedure below, after draining in-flight requests and restarting long-lived
workers. Do not mix old and new auth facades, templates or automatic-logout
JavaScript. There is no new queue, session format or schema migration. Committed
password changes and revocations survive application rollback; returning below
the generation-aware boundary is unsupported.

## Review and scope

The base revision separates native session/request state from consumers, but
does not install SecurityBundle. The authentication ADR and hook inventory
already describe the wider migration. Continue that work as independently
verifiable stages, preserving the existing native authentication mechanisms until
each replacement has equivalent behavior and failure evidence.

Three distinctions govern this implementation:

- `/session` requires console realm 8. About requires an eligible non-guest
  account without a console realm. Only About currently restores Basic and
  remembered logins. A universal authenticator must not erase those differences.
- The credential generation and account policy must be checked on each request.
  A Symfony token is request-local identity, not permission to skip the current
  application checks inside write transactions.
- A database version marker cannot prevent an older binary, which never reads
  it, from accepting credentials. Rollback across the existing generation/MAC
  boundary still needs the documented operational restrictions.

## Implemented request authentication

SecurityBundle runs a stateless custom authenticator on the three reviewed route
names. The authenticator invokes `CurrentActor` for `/session` and
`AuthenticatedAccess` for About. These retain native session provenance,
account/credential checks, revocation, Basic and remembered-token restoration,
transaction ownership and audit/publication ordering. No new password or token
verification algorithm is introduced.

After validation, a self-validating passport carries only the existing actor ID
and username. `SessionIdentity` grants no Symfony roles; realm and resource
policies remain authoritative. Controllers receive Symfony's `CurrentUser` and
retain their existing HTML/JSON denial, redirect, HEAD and method contracts.
They no longer perform a second authentication lookup themselves.

The main-request listener clears the prior Symfony token before routing, including
requests outside the firewall and failures before authentication. Native sessions
retain the PHP engine, storage format, cookie and lock behavior. Symfony's
session bag remains disabled. The reviewed endpoints reject internal subrequests
with 403 because Symfony's firewall authenticates only main requests; a forward
cannot transfer About's weaker realm requirement to `/session`.

The authorization inventory binds these routes to the exact firewall
configuration, controller code, lifecycle listener and delegated native sources.
Changed sources and environment overrides lose admission until reviewed. The
existing architecture and dependency checks remain enforced.

## Automatic logout presentation

`logout.php` now delegates timeout, suspended-account and collector-state messages
to Symfony and `templates/platform/logout_message.html.twig`. Native bootstrap,
remembered-token revocation, cookie/session teardown and all three logout hooks
execute after POST validation in their original order and scope. Ordinary logout
redirects to `index.php` with 303; a reskinning plugin still owns its response and
bypasses Twig. Trusted hooks can still change the reason displayed after teardown.

The native entry captures its existing header and footer, including theme assets,
effective gettext translations and scripts. It passes these through an in-process
`NativeLogoutPage` object to `/logout/message`. Direct HTTP requests cannot create
that object and receive 404; direct POST receives 405. The renderer performs no
identity restoration, database access or credential mutations. This also keeps
the message available after account suspension and session destruction.

Native CSP modes/nonces and outer output/shutdown buffers remain authoritative.
Nested plugin buffers are collected; a plugin that closes the capture boundary
causes a controlled failure. Text and version values are escaped by Twig. The
native HTML head, footer and existing gettext link markup remain trusted
compatibility output. The page retains its selectors and uses a document heading.
Existing gettext message identities are unchanged, so no translation catalog
content changes are required.

## Logout POST confirmation (preceding source snapshot)

`logout.php` uses Symfony Request to validate methods and action shape before
logout hooks or credential mutations. GET and HEAD show confirmation without
teardown; only POST with a valid native `__csrf_magic` token runs the lifecycle.
The Symfony/Twig form targets the existing URL. Other methods receive 405 and
non-string actions receive 400. The native bootstrap remains active, including
its CSRF callback; the explicit mutation guard also refuses an invalid token
when a customized bootstrap defers validation. This retains the native CSRF
bridge; the planned Symfony stateless CSRF manager is not yet in use.

`include/layout.js` submits timeout, suspension and collector-state events using
POST and the current token. Its target is an absolute same-origin URL, independent
of a document's base element. Without a token it navigates to confirmation.
Source inspection confirms that expired tokens handled by the native bootstrap
redirect to GET confirmation through its existing callback.

This deliberately changes GET logout compatibility: menu, theme, plugin and
server redirect links now require confirmation. Integrations needing immediate
logout must submit a valid token using POST. No GET page automatically submits
the form. Existing URLs, POST hook ordering, session formats and credential
mutations remain shared; there is no second write path. Existing gettext messages
are reused, so this slice needs no catalog content changes.

### Verification and separate review

PHP 8.4.25 through mise passes 166 Symfony cases / 213,880 assertions and 32 native
logout cases / 252 assertions, with no failures or skips. The native request
fixture uses the installed csrf-magic implementation for valid, missing, invalid
and expired tokens. Five Playwright cases pass with the shipped JavaScript,
including actual form navigation under CSP, a foreign base element, missing-token
fallback and rejected destinations. Browser responses are intercepted fixtures;
this does not establish an installed login-to-logout round trip.

PHPStan, Deptrac, dependency analysis, PHP style and template validation pass.
The authorization inventory matches all 432 entries and 1,005 classifier fixtures
pass. The reviewed source pins include the native bootstrap, CSRF implementation
and browser caller. Full installed plugin/backend and measured coverage lanes
remain pending. The separate review traced native GET callers and hook ordering.
It caught a reason captured before a trusted hook changed it: the regression failed
before restoring the post-hook read and passes afterward. Future boundary changes
must retain both hook ordering and the values those hooks intentionally mutate.

The local `kadupul-logout-post-source-snapshot.json` binds this verification to
base `44ef2204ec1f3e2711e7f5a555068f055b7ed9b3` plus 49 source/dependency/schema
hashes, both JUnit reports, browser/static-check logs and the three verified
browser dependency assets. Its source-map SHA-256 is
`911896f10efb241be2e2e76f3a0d09b018f493e35fab201aff3b7f1e4fed3312`.
These are local, uncommitted results; historical snapshots below are not evidence
that the entire current patch passed their earlier verification matrices.

## Shared native account-lockout gate

`auth_process_lockout_check()` now invokes the injected `IsAccountLocked` query
through `AccountLockoutStatus` and `NativeAccountLockoutStatus`. Symfony registers
the same private query and adapter; native callers construct them without booting
a kernel. The compatibility facade alone sets the existing global error and
gettext message when locked. An unlocked result preserves an earlier caller error.
This is an architectural migration on main; LTS remains unchanged.

The query checks the current failure limit before looking up an enabled, locked
account. The adapter retains the native scalar comparison and integer truncation,
SQL fields, predicates, bound username/realm and current connection. Integer and
string realm values pass through unchanged. Disabled policy skips all lockout-column
reads, including on the supported pre-upgrade local-login path. No query instance
caches policy or account state. Nonscalar settings and missing bootstrap functions
raise an internal error without exposing input values. Native database-helper
return/exception semantics remain unchanged; this extraction does not establish a
new database availability contract.

Callers still own enablement, credential verification, error response, audit and
session issuance. Local login retains dummy password work and its elapsed floor;
LDAP and domain callers retain the check before directory search/bind; remember-me
restoration rechecks before token rotation or successful audit. The separate review
compares the original helper and all four call sites, including persisted realm
values and the `user_auth` schema. Failure counting and timed unlocking were
native writes at this snapshot and are extracted in the following section. No
schema, dependency or stored-format change is introduced.


## Local login failure recording

`auth_process_lockout()` delegates counter increments, threshold checks, lock
writes and failure-audit persistence to `RecordLocalLoginFailure` through
`AccountLockoutFailure`. The native adapter retains the same prepared SQL and
connection. The use case preserves the atomic increment, disabled-account early
return, post-increment count read, progress-log position, threshold lock, and
failure-audit ordering. The legacy facade retains translated errors and final
address-specific logs, so local, LDAP, domain and password-change callers retain
their established contracts.

Four focused application tests pass on PHP 8.4.25 with 14 assertions. At the
earlier lockout-extraction stage, the installed MySQL 8.4.13 file-session HTTP
run passed 122 authentication and account-lifecycle checks with the shipped
schema and CLI installer. It covered rejected credentials,
counter increments, threshold locking, timed recovery, session admission and
revocation. The host parity run skips 26 database-backed cases without a DSN; it
is not integration evidence. That stage's 738-check total predates the
consolidated current-worktree reports above; the six reports bind to the same
source hashes.

The native SQL helpers retain their existing failure behavior. This extraction
adds no transaction guarantee across account state and audit writes. Deploy the
service, port, adapter, facade and wiring together; rollback restores the previous
facade without schema or session-format changes.

Deploy the facade, query, port, adapter and service configuration together, then
rebuild the environment container. Rollback restores that matching bundle; there
is no state migration to reverse. Gettext message identities remain at the facade.
POT/PO source locations are refreshed; compiled catalogs remain unchanged.

The saved original helper passes 67 characterization cases / 719 assertions before
replacement. On PHP 8.4.25, the migrated native controller/cookie run passes 103
cases / 1,210 assertions under PCOV 1.0.12. The cases cover policy thresholds,
username/realm separation, enabled/locked states, preservation of caller errors,
refusal before a domain bind, and a policy change between initial remembered-token
lookup and token rotation. State readback confirms no token rotation, audit or
failure-count mutation by the gate. Native coverage measures 2/2 query statements
and 11/14 adapter statements; missing settings/lookup bootstrap and malformed-setting
exception statements remain unmeasured.

The selected Symfony suite executes 328 cases / 215,650 assertions: 294 pass and
34 database-dependent cases skip. It includes seven query contract cases and the
architecture suite. PHPStan, Deptrac, container lint and style checks pass.
Eighteen coverage-registration tests pass. The full native suite executes 2,621 cases / 65,258 assertions with zero
failures/errors: 2,596 pass, 24 have existing fixture warnings and one database
case skips. All tests consume the final production/test source for this slice.
The classifier passes 1,065 fixtures and the inventory retains 432 recognized
entries; only the four reviewed installer dependency digests change. The full
gettext verifier passes 1,558 source files, 4,301 current message identities,
195 retained compatibility identities and 23 PO/MO pairs. The repository generator
also repairs accumulated stale source locations without changing translations. Installed database/LDAP/plugin acceptance,
real locale selection, hosted CI and independent review remain release gates.
No browser rerun is claimed because routes, markup and scripts are unchanged.

The local `kadupul-lockout-gate-source-snapshot.json` binds these results to
157 source/artifact hashes at base `44ef2204ec1f3e2711e7f5a555068f055b7ed9b3`.
Its sorted source-map SHA-256 is
`9975e4e82162ee4837594fcd83c6b3064019498651c9b583db303d3175a40a21`. Earlier manifests remain historical evidence.

## Shared password-complexity rules

`PasswordComplexity` now owns the four native complexity checks as pure domain
methods. `NativePasswordComplexity` injects those rules and retains the ordered,
lazy settings reads and translated first-error result. The native
`secpass_check_pass()` facade delegates to that adapter, so login enforcement,
user password changes and administrator password checks all use one rule
implementation. The domain service and adapter are wired privately in Symfony;
native callers construct the same dependency pair without booting a kernel.

The extraction retains byte length, scalar minimum-setting comparisons, ASCII
case conversion, the original special-character set and PHP numeric-string
comparisons. There is no dependency upgrade or policy-strengthening change.
Checkbox settings retain the native string/boolean convention. A null password
argument becomes an empty string at the native facade; other nonscalar password
arguments remain outside its scalar contract. Nonscalar minimum settings and
invalid translation results produce explicit internal failures without including
password values. New password parameters are marked sensitive and the services
do not retain submitted passwords.

Settings defaults, message identities, plugin hooks, current-password verification,
password history, writes and session transitions remain with their existing
callers. Native translation selection still uses the same global gettext helper;
no route, form, schema, hash format or session format changes. LTS is unchanged.
This slice completes extraction of complexity checks; lockout and session issuance
remain later migration boundaries.

Deploy `lib/auth.php`, the domain class, native adapter and service configuration
together and rebuild the environment container. Rollback restores that matching
bundle. The extraction adds no stored state to undo; caller-owned password changes
already committed remain committed. Existing error-message identities are retained.
POT and 23 PO source references now point at the adapter. `xgettext` extracts all
four identities, and `msgfmt --check-format` recompiles all 23 locales to files
byte-identical to the committed MO catalogs, so no binary catalog change is needed.

On PHP 8.4.25, 3,969 scenarios match the saved original helper for return values,
message identities/arguments and settings-read order. The corpus includes omitted,
zero and scalar minimum settings, checkbox combinations, byte/Unicode boundaries,
control characters and native numeric-string comparisons. The focused native
caller run passes 95 cases / 608 assertions. The selected Symfony run executes
321 cases / 215,469 assertions: 287 pass and 34 database-dependent cases skip.
The application/domain and coverage-registration run passes 69 cases / 222 assertions.

PCOV 1.0.12 measures all six domain statements. Native controller/password/session
coverage passes 81 cases / 517 assertions; six direct native-password cases pass
with 55 assertions and validate their source registration. Four additional
password-change regressions pass with 40 assertions, exercising number, case,
special-character rejection and admission through the real native save path.
Rejected changes preserve the stored password and session; admission uses the
existing revocation path. Combined native measurements cover all six domain
statements and 16 of 19 adapter statements. Missing-bootstrap, invalid-setting
shape and invalid-translation exception statements are not claimed as covered.

The separate review traces all four native callers and the injected login-policy
adapter, verifies the original comparisons and setting types, and checks gettext
extraction and existing native failure/session gates. PHPStan, Deptrac, container
lint and changed-file style pass. Eighteen coverage-registration tests and 1,050
classifier fixtures pass; the inventory retains 432 recognized entries. Only
reviewed dependency digests change. Native policy/credential persistence, installed
LDAP/plugin/backend acceptance and current-head CI remain separate release gates.
Browser tests were not repeated because the form, scripts and routes are unchanged.

The existing full native suite completes 2,597 cases with 64,967 assertions and
zero failures/errors: 2,572 pass, 24 report existing fixture warnings and one
database case skips. It ran against the final production code before the four
additional save-path tests were appended; those four pass separately on the final
test source. The manifest records the test-source identity consumed by the broad
run. Warning-bearing and skipped cases are not claimed as clean passes.

The local `kadupul-complexity-source-snapshot.json` records base revision
`44ef2204ec1f3e2711e7f5a555068f055b7ed9b3`, locked dependencies, catalogs and
150 source/artifact identities. Its sorted source-map SHA-256 is
`dba2f6c00a98ff459a7562c7d8445f66245b92172d3a38241c104ea184be149f`.
It also records the parity corpus/result digests, compiled catalog hashes and
source-bound report paths. Previous slice manifests remain historical evidence.

## Policy after local password verification

`ApplyLocalLoginPolicy` now coordinates forced password changes and login activity
after the native password verifier accepts the password. Its `LocalLoginPolicy`
port is implemented by `NativeLocalLoginPolicy`, which retains settings reads,
the existing complexity checker, translated messages, logging and parameterized
writes on the native connection. `secpass_login_process()` remains the native
composition boundary; the same private command/port binding is registered in
Symfony. No kernel boot, route, session issuer or second write path is added.

Settings are read lazily in the original order. Disabling forced complexity does
not call the checker. A compliant password does not require permission to change
it. A noncompliant password either marks the local enabled account for a password
change and publishes the existing notification, or returns the existing generic
denial before reading the expiry setting or recording activity. The permitted
path continues to native session issuance so the password-change page remains
reachable. The application operation itself never redirects or issues a session.

The account ID reaches persistence without coercion; activity retains the supplied
username. Both writes retain their local-realm and enabled-account restrictions.
The existing `password_change` flag and settings retain supported native string
and boolean semantics. Password arguments are marked sensitive and are not stored
in the command or port. Message identities and native locale selection do not
change. Lockout, password verification, password history and final session eligibility
remain in their existing implementations. The subsequent shared-complexity slice
above now supplies the rule implementation.

This is an architectural migration targeting main; LTS is unchanged. No dependencies,
runtime floor, schema, hash format or session format change. Native database
helpers retain their existing failure and transaction semantics: this extraction
does not make the two writes atomic or introduce persistence readback confirmation.
Exceptions propagate to the existing caller and its timing floor; this is not a
claim that every legacy database failure outcome has been redesigned.

Deploy `lib/auth.php`, `ApplyLocalLoginPolicy`, its port, `NativeLocalLoginPolicy`
and `config/services.yaml` together, rebuild the environment container and restart
long-lived processes. Each request uses one policy implementation. Rollback restores
the matching source/configuration bundle and cache. Both versions read the same
persisted state; rollback does not reverse a password-change requirement or login
activity already written.

Local PHP 8.4.25 verification includes 85 focused native cases / 769 assertions
and 14 policy scenarios / 124 assertions against the saved preceding implementation.
These cover settings-read order, denied versus permitted password-change handoff,
expiry defaults and disabled/zero/negative expiry, bounded write counts and native
messages. SQLite-backed controller tests include a same-name account in another
realm and verify it remains unchanged. The selected Symfony run executes 290 cases
with 215,096 assertions: 256 pass and 34 database-dependent cases skip. The focused
application/coverage-registration run passes 38 cases / 137 assertions.

PCOV 1.0.12 measures all 10 application statements through both direct tests and
native controller calls, and 23 of 29 adapter statements through 20 native cases
with 239 assertions. The six missing-bootstrap exception statements are not
claimed as covered. Both native controller and direct-password producers register
the moved sources and require measured execution for admitted local passwords.
Their negative evidence checks continue to reject omitted sources. Existing
browser tests were not rerun because this slice changes no form, script or route.
Installed LDAP/plugin/backend acceptance and current-head CI remain release gates.

PHPStan, Deptrac, container lint and changed-file style pass. Eighteen coverage
registration tests and 1,040 classifier fixtures pass. The authorization inventory
retains 432 recognized entries; only the four existing installer dependency-digest
entries change. The separate review compares the original post-verification
branch, native caller error/session gates, settings types, schema columns and
conditional SQL. Query counts remain bounded at one flag write and one activity
write; no extra account lookup or verification is introduced.

The final full native authentication run discovers 2,597 cases with 64,967
assertions and zero failures/errors: 2,572 pass, 24 report existing fixture
warnings and one supported-engine case skips. Warning-bearing cases are not
claimed as clean passes. The warnings remain the server readiness probe and
spike-removal fixture cleanup; the skipped database case remains a release gate.
The run completes on the final source snapshot below. Its elapsed duration is not
a production performance measurement; the host experienced competing load.

The local `kadupul-login-policy-source-snapshot.json` records base revision
`44ef2204ec1f3e2711e7f5a555068f055b7ed9b3`, locked dependencies, settings/schema
contracts and 92 source/configuration identities. Its sorted source-map SHA-256 is
`d41d114f82c31ea13c4d865ea0d687dafaf00d61018be1fafec8601c71dde13e`.
Earlier failed probes and preceding slice manifests remain historical evidence.
New application/native cases run through the existing test discovery and CI lanes.

## Local credential confirmation and rehash coordination

`VerifyLocalCredentials` now coordinates the native local login's single password
verification, fresh realm-zero account read, exact hash confirmation and optional
rehash. `NativeLocalCredentials` implements its application port using the
existing password policy and persistence helpers. The application code receives
neither an HTTP request nor a session and accesses no globals. The native facade
constructs the adapter directly; the same private service binding is available
in Symfony without booting a kernel inside credential verification.

`local_auth_login_process()` retains `login_process` before delegation and the
elapsed timing floor in `finally`, including plugin ownership and dependency
exceptions. Existing policy errors and extension account fields are preserved.
The password is read again only if rehashing is needed; the transaction remains
`auth_rehash_password_preserving_sessions()` on the existing connection. It keeps
the credential generation and other sessions. No second verification, database
connection, mutation path or session issuer is added. The returned row retains
its prior hash, as before; native session transition binds the committed generation.

The service refuses a missing or changed fresh hash and an unconfirmed rehash.
Malformed hash fields now produce the existing generic denial rather than an
invalid offset/type error. The adapter requires the native bootstrap and reports
missing helpers as an exception; the facade's timing floor still applies.
Account eligibility and issuance remain the caller's responsibility: returning
a confirmed row does not grant access or bypass the native session transition.

This is an architectural improvement on the migration branch; LTS is unchanged.
`secpass_login_process()` retains lockout and native verification. The subsequent
policy slice above now coordinates forced-change handoff and expiry bookkeeping. LDAP, HTTP login parsing, CSRF and final session
issuance remain future migration slices. No dependency, PHP minimum, schema,
message identity, hash format or session format changes in this slice.

Deploy `lib/auth.php`, the command, its `LocalCredentials` port,
`NativeLocalCredentials` and `config/services.yaml` as one source bundle, rebuild
the environment cache and restart long-lived processes. Each in-flight request
uses one implementation. Rollback restores the matching bundle and cache; both
versions use the same persisted formats and transaction. Rollback does not undo
completed password changes or restore revoked credentials.

The separate review compares the extracted flow with the preceding native
implementation, its `auth_login.php` caller and realm selection, actual password
column and credential-generation consumers. Focused tests cover plugin-owned
login, pre-existing errors, lazy request reads, byte-sensitive hash changes,
missing accounts, rehash refusal and dependency exceptions. Eleven compatibility
scenarios also pass against the saved original implementation. Native controller
cases exercise actual login and session handoff with SQLite-backed persistence;
supported-engine and installed plugin acceptance remain distinct release gates.

On PHP 8.4.25 selected through mise, the final selected Symfony run executes
275 cases with 214,727 assertions: 241 pass and 34 database-dependent cases skip.
The application and coverage-registration run passes 23 cases / 80 assertions;
PCOV 1.0.12 measures all 14 application-service statements. The focused native
run passes 76 cases / 665 assertions; 11 compatibility cases pass against the
preceding implementation with 102 assertions. The final native coverage run
passes 16 cases / 193 assertions and measures 10 application-service statements
and 19 of 28 adapter statements through actual native login/controller paths.
Missing-bootstrap branches are not claimed as covered by that run.

PHPStan, Deptrac, container lint and changed-file style pass. Eighteen coverage
registration tests pass; 1,025 classifier fixtures pass, and the inventory retains
432 recognized entries. Coverage producers and consumers now register the moved
command/port/adapter, require actual execution on native login probes and reject
missing source evidence. The initial coverage run correctly rejected three
unregistered logout adapters in the shared harness; registration was completed
and the run repeated successfully. No assertion, baseline rule or coverage gate
was weakened. Browser tests were not repeated for this slice because its
production changes do not touch the rendered form or scripts.

The broader native authentication suite discovers 2,588 cases and completes with
zero failures/errors and 64,860 assertions: 2,563 pass, 24 report warnings and one
supported-engine case skips. The warning-bearing cases retain the existing
fixture server readiness probe and spike-removal cleanup warnings; they are not
claimed as clean passes. The broad run uses the final production implementation;
the coverage-only shared registration update made during it is verified separately
by the final 16-case PCOV run above. Installed supported-engine, LDAP and plugin
acceptance remains pending, as does dependency analysis on an LDAP-enabled runtime.

The local `kadupul-local-credential-source-snapshot.json` records base revision
`44ef2204ec1f3e2711e7f5a555068f055b7ed9b3`, the locked dependencies, schema and
83 source/configuration identities. Its sorted source-map SHA-256 is
`d777c1b9fc1bb852f085ab998d52a38fc4bb6d1465d78d6c8f270b3f071c12f6`.
The prior presentation/hasher manifests and earlier failed reports remain
historical evidence. The new command and native regression cases are discovered
by the existing Symfony/native runners; no new optional test lane is required.

## Login presentation through Twig

`auth_login.php` now resolves the public, constructor-injected `LoginPageRenderer`
at the native composition boundary. Its Twig blocks render in the same order as
the previous PHP markup, between `login_title`, `login_before`, `login_realms` and
`login_after`. Hooks retain native scope, printed output, ignored/used return
semantics and the ability to stop after previously rendered content. The bootstrap runs in an isolated scope so its environment/debug locals do not
overwrite plugin globals. A custom reskin returns before Symfony boots. The renderer does not load an account or
access the database; no new route or credential issuer is introduced.

The native entry still owns POST validation, authentication, realm selection,
credential persistence, native header/footer and csrf-magic output rewriting.
There is one active write path. Form names, selectors, relative submission URL,
remember choice, realm storage and keyboard focus remain compatible. Passwords
are never copied into the rendered form. Existing native escaping is retained
for usernames, realm names, errors and locale through typed `Twig\Markup` values;
Twig escapes ordinary translated labels, realm values, the target and version.
Trusted plugins still own their printed markup and execute in process.

This is an architectural improvement on the migration branch. LTS is unchanged.
No dependencies, runtime floor, schema, session format or gettext message IDs
change. The form is presentation only; Symfony Forms/stateless CSRF and the complete login
application use case remain future slices. Native locale selection is unchanged;
fixture translations do not establish installed user-preference behavior.

Deploy `auth_login.php`, `LoginPageRenderer`, `templates/identity_access/login.html.twig`
and `config/services.yaml` together. Rebuild the environment container and Twig
cache before serving the new source. Rollback restores the four matching files
and rebuilds the cache. The presentation slice has no persisted state to undo;
credentials issued by requests continue to belong to the native implementation.

Local verification on PHP 8.4.25 includes 21 login presentation/hook cases with
327 assertions and 13 comparisons with the saved original implementation. The
comparisons normalize whitespace/comments and generated nonce/token values;
control attributes, script behavior, hook observations and CSRF validity match.
Four Chromium cases pass with the actual native CSP policy builder, shipped
JavaScript and rendered Twig/native output: keyboard POST, token handoff,
remember choice, restored realm and both focus branches. HTTP responses are
intercepted fixtures; these checks do not authenticate against an installed
backend. The browser suite is registered in `test:themes`, with PHP dependencies
installed before execution and Symfony feature target branches included in CI.

PCOV 1.0.12 measures all nine renderer statements through the configured service;
the combined native form/domain regressions measure six renderer statements
before their deliberate early exits and validate subprocess source/evidence omissions. PHPStan,
Deptrac, Twig/container lint and workflow lint pass. The authorization inventory
retains 432 recognized entries; 1,010 classifier fixtures pass. Only reviewed
source digests changed, including the existing installer service-wiring pins.

The final selected Symfony run discovers 374 cases: 336 pass with 214,928
assertions and 38 database-dependent cases skip without a configured test DSN
(About storage, link sessions and native session persistence). The 41 native
form/domain coverage cases pass with 217 assertions and validate the new source
registrations and missing/stale evidence rejection. The domain fixture now
supplies the native charset boundary and boots the test container; its previous
missing-helper error is preserved in the earlier failed full-run report.

The separate review traces the original credential switch, template/guest
fallbacks, session transition and redirect through the unchanged write path,
then compares rendering stages and hooks with the prior PHP page. Translation
message IDs are unchanged, so there are no catalog additions. Ordinary labels,
version text and realm values now receive Twig escaping; plugins that need
markup retain their explicit output hooks. Native entity/charset behavior for
usernames, realm names and errors is retained. Production container warmup and
public renderer resolution pass. No blockers remain for this presentation slice;
installed backend/plugin/locale acceptance and full release CI remain required.

The final native authentication run completes 2,577 cases / 64,680 assertions
with zero errors or failures: 2,552 clean passes, 24 warning cases and one database
skip. The existing plugin server readiness probe and spike cleanup warnings
remain open. Earlier incomplete runs and the missing-charset fixture failure are
not counted as passes. The final four-browser, original-page comparison and
production-container probes explicitly select PHP 8.4.25 for child processes;
earlier probes inherited Homebrew PHP 8.5.12 and are historical evidence only.
A separate PCOV probe executes the complete native rendering through its footer,
verifies native CSRF and hook effects, and records both entry and renderer lines;
that standalone artifact is not imported into PHPUnit coverage.

`kadupul-login-presentation-source-snapshot.json` records 74 source/dependency
identities against base `44ef2204ec1f3e2711e7f5a555068f055b7ed9b3`, with final
JUnit, coverage, browser and check digests. Source-map SHA-256:
`bc6adf28a43b080f56669af3ead5d0e7b665b2c49390524656ccb7db771dd87d`.
Sources were checked unchanged across final verification. Evidence is local in
`/private/tmp`; the work is uncommitted and has not been published or deployed.

## Shared password verification

`LegacyPasswordHasher` implements Symfony's `PasswordHasherInterface` and is
registered as the named `legacy_native` hasher in the security configuration.
The active `compat_password_verify`, `compat_password_hash` and
`compat_password_needs_rehash` facades now delegate to it. Login, password history,
password changes and native account writers therefore share the implementation
with future Symfony login consumers. The facade normalizes legacy null values
before entering the typed service. Account admission, lockout, hooks, audit and
credential persistence still belong to the existing native operation.

The service preserves the installed PHP password primitives, `PASSWORD_DEFAULT`
cost, explicit algorithm/options, byte semantics and error behavior. Existing MD5
records remain verifiable and receive the existing default-cost padding using a
fixed safe input; new hashes use the selected native algorithm. The existing login
timing floor and transaction that preserves sessions during rehash are unchanged.
This deliberately avoids Symfony's default prehash transformation for long or
NUL-containing bcrypt input, which would change compatibility with native writers.
Empty-password admission remains a login-policy decision, separate from the
hashing primitive. No password storage, session format or schema changes occur.

The already-installed `symfony/password-hasher` 7.4.8 is now a direct dependency.
Composer refreshed the lock metadata without changing package versions, and the
locked and installed package/version sets match. No dependency or runtime upgrade
is part of this slice. LTS is unchanged; this is an architectural migration.

At the historical password-hasher source snapshot below, PHP 8.4.25 passes 184 Symfony cases / 213,951 assertions, including 18 new
compatibility cases. The broader native auth run reports 2,577 cases / 64,680
assertions with zero errors/failures: 2,552 clean passes, 24 warning cases and
one database case skipped without its DSN. The warning cases are the plugin
redirect server readiness probe and 23 spike fixture cleanup warnings; they remain
verification gaps. The initial sandbox run failed its localhost fixtures; the
authorized rerun is the final result. PCOV 1.0.12 records all seven executable statements
of the new hasher in both its compatibility suite and two real native login
scenarios; the latter also validate subprocess evidence and source omissions.
These are isolated fixtures, not an installed browser login or whole-patch
coverage claim. PHPStan, Deptrac, container validation, Composer validation and
PHP style pass. Dependency analysis reports `ext-ldap` unused because the selected
local PHP build has no LDAP extension; existing LDAP callers remain in `lib/ldap.php`.
An extension-complete runtime is still needed for that check. No ignore was added.
The authorization inventory matches 432 entries and 1,010 classifier fixtures
pass. Ten session-restoration coverage cases / 107 assertions also validate the
updated source registrations. The inventory's four installer review digests
changed with the shared helper; their admission gates did not change.

The separate review compared the facades with the previous native primitives,
their account/history/change-password callers and the non-null `user_auth.password`
schema. It retained permissive legacy scalar/null conversion at the facade and
kept native rehash persistence separate from hashing. The migration does not add
an account issuer, automatic password migration or second credential writer.

`kadupul-password-source-snapshot.json` records 54 source/dependency/schema hashes
against base `44ef2204ec1f3e2711e7f5a555068f055b7ed9b3`, final JUnit reports,
selected PCOV reports and check logs. Its source-map SHA-256 is
`8bfb2c5a5860f4b316517b020e1c4672a7ed1e4fdac872e54174ad6f85f82c32`.
The snapshot and results are local; no commit, publication or deployment is implied.

Deploy the native facade, new class, security configuration and Composer metadata
together. Rollback restores those sources together; hashes written by this slice
retain the native format and remain readable by the prior compatible version.
The existing prohibition on rolling back below generation-aware authentication
still applies. Local login's application operation remains planned; its native
form now uses the Twig presentation slice above.

## Shared Logout Everywhere transaction

`NativeSessionRevocation` now owns the existing Logout Everywhere transaction.
Its injected `DatabaseConnection` entry is available to Symfony services through
the existing IdentityAccess resource registration. The native
`auth_logout_everywhere()` facade delegates to the same implementation with its
resolved PDO connection. No second mutation algorithm remains in `lib/auth.php`.
This is an architectural migration on main; it is not an LTS defect correction.

The active HTTP write path remains the POST/CSRF-protected profile action. That
caller still takes identity and credential binding from the session, audits the
outcome, returns 503 on refusal and expires the browser session only after a
confirmed commit. The persistence service is not an HTTP authorization boundary;
future Symfony use cases must supply an authenticated actor and current binding.
Database acquisition errors propagate to the injected caller; transaction
refusals retain the existing false outcome. No new route is exposed.

The transaction preserves the original connection, MySQL InnoDB and temporary-table
checks, account row lock, generation check/readback, token/session deletion and
commit order. It refuses a caller-owned transaction and rolls back only its own
failed work. Passwords, ordinary preferences and other accounts remain unchanged.
The compatibility entry binds scalar legacy IDs without first converting them to
integers; malformed non-scalar identities are refused. Explicit helper-return and
metadata type checks guard the new strict PHP boundary. Credential-generation
interpretation remains in the native helper, including transparent rehash behavior.

The native facade and new service must ship together. No configuration, schema,
credential format, dependency or runtime change is needed for this extraction.
Returning to the preceding compatible implementation does not restore credentials
already revoked. It continues to read the same persisted generation, so rollback
of this extraction does not require a data migration or a dual-write period.

## Database verification continuation (historical source snapshot)

`NativeSessionRevocationDatabaseTest` imports the four affected table definitions
from `cacti.sql` into a fresh random schema for every case. All 23 cases / 287
assertions pass on MySQL 8.4.13 and MariaDB 11.8.9 using PHP 8.4.25 and PHPUnit
10.5.64. PHPUnit 12.5.33 also passes the 23 cases / 287 assertions on MySQL.
The isolated servers use InnoDB and strict SQL mode. Each completed run leaves
no owned schemas behind; the disposable containers are removed after verification.

The cases exercise both the native facade and injected service, genuine native
rehashing, stale/malformed credentials, write/readback failures, a refused commit,
caller-owned transactions, nontransactional tables and populated temporary-table
shadows on the actual mutating connection. Removing a shadow admits the same
account, demonstrating that refusal is not caused by an empty fixture. The
settings table's nontransactional case uses utf8mb3 because MyISAM cannot index
its full 255-character utf8mb4 key; ordinary cases use the installed InnoDB schema.

Three independent-connection cases observe the server's lock-wait graph before
allowing the rival transaction to finish. A committed password replacement
refuses the old binding; a rolled-back replacement or a transparent rehash allows
revocation. These cases exercise the production service in the parent test process.
The rival performs only fixture SQL, so there is no unregistered production-code
coverage producer. No change to the revocation algorithm was needed.

The existing six-engine database-contract CI matrix now runs this test explicitly,
with `--fail-on-empty-test-suite` and `--fail-on-skipped`. Normal local suite runs
retain the existing convention of skipping database contracts without a DSN.
PHP style, workflow validation (`actionlint`) and diff checks pass. The local
full-dev checkout's direct PHPUnit 12 invocation initially mixed PHPUnit 10 and
12 autoloaders; the successful local 12 run puts the test loader first. CI installs
application dependencies without dev packages. Hosted CI, the remaining four
engine versions, measured coverage and installed browser/plugin verification are
still pending. This evidence covers the local MySQL/MariaDB transaction gap at
the historical source snapshot below; the transaction source is unchanged by
the subsequent POST confirmation slice.

The local `kadupul-revocation-database-source-snapshot.json` records the image
identities, three JUnit reports, PHPUnit 12 bootstrap and 40 source/dependency
hashes, including the imported install schema. Its source-map SHA-256 is
`6d5505d58bbcf8b1cdcb998cd901bdf819f4b8cb362080452a901876235013d5`.

## Revocation extraction verification (historical source snapshot)

PHP 8.4.25 through mise passes 166 Symfony cases / 213,880 assertions and 100
native auth/profile cases / 480 assertions, with no skips or failures. Eighteen
new service/facade cases cover successful revocation, preserved rehash bindings,
stale/malformed bindings, bound-ID compatibility, failed generation/token/session
writes, altered readback, refused commit and caller-owned transactions. The
existing native profile tests verify browser cleanup only after commit and the
remembered-login interleaving contract. Real PHP file/database session fixtures
now revoke through the injected service before testing subsequent access.

The final diff was reviewed against the original native transaction and the
profile action. SQL, connection ownership, credential mapping and mutation order
are retained; the profile authorization/CSRF and post-commit effects are unchanged.
PHPStan, Deptrac, dependency analysis, container lint and changed-PHP style pass
without new baseline suppressions. Coverage producer/importer lists now include
the extracted service, require its execution for revocation scenarios and retain
missing-source rejection checks. Measured PCOV execution and actual MySQL engine,
locking and concurrent-connection verification remain pending. SQLite fixtures
cannot establish those MySQL properties. Full installed/browser and collector
acceptance remains a release gate.

The classifier passes 998 fixtures; the inventory verifies 432 recognized entries
with none unknown. The extracted service is pinned wherever the native auth source
was pinned, including mutation-refusal fixtures. The local
`kadupul-revocation-source-snapshot.json` records 37 changed source/dependency
identities against base `44ef2204ec1f3e2711e7f5a555068f055b7ed9b3`, both final
JUnit reports and source-map SHA-256
`8aeda17532ffcfff0c5b76bfe65d08918d9297cfed041270fc4ca8d6adeedc66`. Earlier snapshots below
are historical evidence, not claims for this revised source.

## Next stages

| Stage | Deliverable | Required evidence before cutover |
| --- | --- | --- |
| Request authentication, remaining routes | Extend the reviewed route set by workflow family; remove duplicate identity reads where callers permit it. | Preserve guest policy, realm checks, forced-change behavior, collector restrictions and permission revalidation inside mutations. |
| Logout mutation | Symfony/Twig POST confirmation and automatic POST callers are implemented with native CSRF and lifecycle compatibility. Symfony stateless CSRF and the revocation application use case remain planned. | Full installed plugin/backend tests, confirmed persistence outcomes and legacy credential interoperability. Native credential mutations remain active. |
| Local login | Shared Symfony-compatible password verification/hashing is implemented. Twig form rendering, credential/policy coordination, complexity rules and the shared lockout gate are implemented. Failure counting, timed unlocking and session issuance remain native. | Timing floor, unknown/disabled/locked users, template/guest fallbacks, lockout, no publication before confirmed audit/commit, actual `login_process` behavior. |
| Rate limiting | Address-first limiter with canonical address identity and bounded account buckets. | Refusal without account lockout, concurrent admission, unavailable storage, documented multi-node behavior. Add its dependency with the implementation. |
| Basic, LDAP and domains | Extend request authentication and use Symfony LDAP behind the provider boundary. | Server-verified principal provenance, mapping, TLS, search failure versus no-match, group checks, realm numbering, encrypted bind credentials and disposable directory tests. |
| Password change and Logout Everywhere | Symfony forms and application use cases; the shared Logout Everywhere persistence transaction is implemented. | Forced-change routes, rehash versus password-change semantics, concurrent sessions, failed commit/readback, collector propagation and rollback restrictions. |
| Retirement | Remove superseded issuers/renderers after reconciling all callers and applicable maintenance fixes. | Full installed HTTP/plugin/backend matrix and no remaining callers. Retain `include/auth.php` while legacy gated pages exist. |

Before moving hooks into a worker, characterize both return values and changes
to globals/session state. Capturing printed markup alone does not preserve those
effects. The current hook inventory is useful source evidence, not that runtime
characterization. Keep hook ownership in the existing path until the full
handoff contract is tested.

## Logout presentation verification (historical source snapshot)

PHP 8.4.25 through mise passes 80 Symfony cases / 213,314 assertions and 22 native
logout cases / 146 assertions, with no skips or failures in either run. These
exercise the shipped logout entry, native token revocation, hook order and state,
a plugin restarting a real native file session after teardown, reskin short-circuit,
teardown exceptions, nested buffers, the private rendering handoff, subdirectories,
HEAD, CSP/nonce retention and escaped text. The renderer tests also forbid database
access and Basic/remembered-login restoration.

PHPStan, Deptrac, container/Twig lint, changed-file style and dependency analysis
pass. The authorization classifier passes 994 fixtures and the inventory has 432
recognized entries with none unknown. The new presentation route is bound to its
reviewed source files; changing a guard or handoff loses admission. Existing root
and native test runners discover the cases. Native coverage producers and importers
register the new source/template identities, and the controller coverage cases
require actual execution of all three new PHP presentation classes.

The local `kadupul-logout-source-snapshot.json` records the same base commit as the
first slice and 31 non-documentation source/dependency identities. The SHA-256 of
its sorted compact source-hash map is
`c873040644582db552e2084d023d38e43e0a19543a35c8a137cb77d975584a09`.
The full installed plugin/backend matrix, browser interaction under each CSP mode
and measured coverage execution remain pending. These local tests do not establish
the correctness of every native teardown failure outcome or complete the planned
logout mutation migration.

## First-slice verification (historical source snapshot)

Local PHP 8.4.25 selected through mise passes 38 new kernel/native-session cases
(288 assertions), including both storage backends, current/revoked credentials,
disabled/locked/guest accounts, forced password change, console denial, preserved
payload bytes, subdirectory redirects and repeated requests. The existing native
About HTTP suite passes 32 reported scenarios for both backends, including
Basic/remembered restoration and persistence/audit/commit failure outcomes.

The focused Symfony regression run discovers 276 cases: 252 pass and 24 are
skipped, with 214,126 assertions. Skips are not passes. The classifier passes
985 fixtures, including mutated copies of every firewall dependency. Container
lint, changed-file PHP style on PHP 8.4.25, PHPStan, Deptrac, Composer validation
and dependency analysis pass. The authorization inventory verifies 431 entries
with none unknown. Coverage registration passes 18 tests, including missing/stale
producer hashes and importer/self-test parity. The new kernel tests are discovered
by the existing `phpunit-symfony.xml` suite and its CI job. Both HTTP coverage
imports now require measured execution of the four firewall classes.
These are local source/fixture checks; the full installed collector/plugin,
database-engine and coverage CI lanes remain release gates.

The broader legacy `Unit/Security/Auth` run discovers 2,567 cases and completes
with zero failures/errors and 64,574 assertions: 2,542 pass, 24 report warnings,
and one supported-engine case is skipped. The warnings come from unchanged
fixtures: a localhost server-startup connection probe and 23 spike-removal fixture
cleanup attempts that leave their stub `lib/rrd.php` behind. A focused rerun with
warning details confirms those causes; the warning-bearing cases are not claimed
as clean passes. Initial sandbox socket failures remain in the local failure log;
the completed broad run used permitted loopback fixture servers.

The tested source is based on `44ef2204ec1f3e2711e7f5a555068f055b7ed9b3`.
The local `kadupul-auth-source-snapshot.json` manifest records all 19 changed
non-documentation files plus `tests/composer.lock`; the SHA-256 of its sorted,
compact source-hash map is
`f9b857e6b649bd7f67593eb9ac43851d227393a77e698b8fd510de5fb3a501c4`.
It includes the root dependency lock and the coverage/classifier registrations.

Composer's audit reports 13 pre-existing advisories in nine other locked Symfony
packages; none names `security-bundle`, `security-http` or `security-core`.
This slice adds two packages and updates only `security-core` among existing
packages. The broader dependency findings remain a separate release concern.

## Deployment and rollback

Deploy the PHP source, controller changes, logout Twig template, `include/layout.js`, bundle/firewall
configuration and Composer lock together. Install locked dependencies, clear each deployed
Symfony environment's cache and restart long-lived workers. No schema, setting,
native-session payload, cookie format, PHP minimum or LTS change is required.
The logout entry retains native credential mutations behind POST validation and
delegates confirmation/message rendering. Deploy the logout entry, presentation
adapters, template and automatic POST caller together. The existing
`get_md5_include_js()` loader invalidates the layout asset URL from its bytes.
Deploy `lib/auth.php` and `NativeSessionRevocation` together for the profile action.
No dual writes are added.

Rollback restores the preceding compatible source, configuration and dependency
lock together, then clears caches and restarts workers. Sessions continue to use
their existing native format. Rollback does not undo revocation or administrative
changes already committed, and does not authorize downgrading below the existing
generation-aware authentication boundary. Reverting POST confirmation also
restores the earlier GET logout behavior; assess that compatibility change before
rolling back. Application rollback cannot restore revoked credentials.
