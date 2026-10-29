# 0010: Symfony Security for login and logout

## Status

Proposed. This record is a plan. No production code accompanies it.

## Context

Login, logout, remembered login, password change, Basic and LDAP authentication
and session binding are still legacy PHP: `auth_login.php`, `logout.php`,
`auth_changepassword.php`, `auth_profile.php`, `include/auth.php` and
`lib/auth.php`. [The inventory](../migrations/authentication-inventory.md) lists
the surface, the stored formats and the 22 plugin hooks involved.

`IdentityAccess` already reads the legacy session from Symfony and rechecks the
account, and it can restore Basic and remembered logins for the About route. That
bridge duplicates parts of the legacy logic in a second implementation, and
Symfony cannot issue or revoke a login. `docs/architecture.md` names this as the
open IdentityAccess item: move credential issuance, external providers,
remember-me, logout and CSRF to Symfony security integration before removing
native-session compatibility.

Constraints that shape the decision:

- Stored state is shared. Sessions, `user_auth_cache`, `settings_user`
  (`auth_credential_generation`) and `user_log` are read and written by both
  stacks during the migration, and `lts/1.2` and external reports read them.
- The credential generation and the remembered-token MAC are security properties
  (revocation, replay resistance). A change in their format is a revocation event.
- Hooks are public API ([VERSIONING.md](../../VERSIONING.md)). Several hooks
  render pages or echo markup, so they cannot simply be dropped into a Twig page.
- `include/auth.php` is the authorization gate of every protected legacy page.
  It cannot be deleted while any such page exists.
- Main only. `lts/1.2` keeps its legacy authentication unchanged.

## Decision

Adopt `symfony/security-bundle` 7.4 for login, logout and request authentication
on `main`, in stages behind one switch, with no schema change.

1. **Packages.** Add `symfony/security-bundle`, `symfony/ldap` and
   `symfony/rate-limiter`, all `7.4.*`, in the implementing change; `7.4.22`,
   `7.4.10` and `7.4.22` are the latest 7.4 releases at the time of writing.
   `ext-ldap` is already required. `symfony/security-core`, `security-csrf` and
   `password-hasher` are already installed.

2. **Identity stays in the legacy session.** The firewall is stateless from
   Symfony's point of view. A single authenticator derives the user on each request
   from the shared session payload (the existing `SharedSession` and
   `LegacyBrowserAuthentication` code, which moves behind the authenticator) and
   rechecks eligibility and `sess_user_credential` exactly as
   `auth_session_check_eligibility()` does. Symfony's session bag stays disabled, so
   there is one payload format. Login establishes the session through
   `NativeAuthenticationSession::establish()`; logout revokes through
   `revoke()`.

3. **A custom authenticator over `user_auth`.** A read-only DBAL user provider
   returns the existing row. Passwords verify with a custom
   `PasswordHasherInterface` that accepts every format `compat_password_verify`
   accepts and reports `needsRehash` as `compat_password_needs_rehash` does. The
   rehash writes through the same transactional operation as
   `auth_rehash_password_preserving_sessions()`, which preserves the credential
   generation. Verification keeps the 1000 ms timing floor and the behavior of
   `auth_unknown_user_password_verify()`, which has to be read and pinned by a
   test before it is reimplemented.

4. **Remembered login is implemented, not replaced.** The authenticator reads
   `cacti_remembers` and reproduces the scheme byte for byte: cookie
   `userid,realm,token`; `sha512(token)` stored in `user_auth_cache` with the client
   address; versioned tokens `v1.<64 hex>.<hmac_sha256(64 hex, generation)>`;
   rotation on every restore; refusal for the guest account, a changed address, a
   locked or disabled account, or a stale generation. Symfony's own remember-me
   handler is not used, because its token format and storage differ and cannot
   honor the existing generation.

5. **Account lockout is unchanged and stays authoritative.** `secpass_lockfailed`
   and `secpass_unlocktime` keep their semantics and storage on `user_auth`
   (per username and realm). A Symfony login failure calls the same increment and
   unlock logic through an application port.

6. **Rate limiting is additive.** `symfony/rate-limiter` adds a per-address limiter
   (IPv4-mapped IPv6 canonicalized before keying) and a per-username-and-realm
   limiter ahead of credential verification. A limiter refusal returns 429 and
   never locks an account, so an attacker cannot lock out a real user through the
   limiter. The address bucket is checked before any username bucket is allocated.
   Limiter state lives in a `symfony/cache` pool on the filesystem under `var/`
   (per node) at first; see open question 4 for multi-node.

7. **LDAP through `symfony/ldap`.** Method 3 and the domain method 4 move to
   `symfony/ldap` behind a port. Preserved: the TLS certificate requirement and
   its settle logic (`cacti_ldap_tls_settle_requirement`), filter escaping through
   the library's `escape()` in place of `cacti_ldap_filter`, search-then-bind and
   direct-bind modes, group membership checks, the domain realm numbering
   `1000 + domain_id`, creation of a user from the template account, the guest
   fallback, and the non-disclosing error messages
   (`LdapLoginErrorDisclosureTest`). `lib/ldap.php` stays until the legacy login is
   retired.

8. **Basic authentication.** The authenticator trusts only the native
   `REMOTE_USER` or `REDIRECT_REMOTE_USER` set by the web server, as
   `LegacyBrowserAuthentication` already does. An `Authorization` header or
   `PHP_AUTH_USER` alone never authenticates.

9. **CSRF.** Symfony login, logout and password-change forms use Symfony stateless
   CSRF with same-origin evidence. Legacy pages keep csrf-magic until each page
   migrates, per the existing CSRF direction for `main`. The login form needs no
   csrf-magic token on the Symfony path.

10. **Plugin hooks keep their names, order, arguments and return semantics.** A
    hook port in `Infrastructure/Legacy` calls hooks through the isolated legacy
    worker pattern already used for plugin menu entries, because plugins are
    trusted in-process legacy code that expects legacy globals. Per hook:
    - Hooks around the form (`login_title`, `login_before`, `login_after`,
      `login_realms`, `change_password_title`, `auth_profile_tabs`) are called with
      the same arguments as today. Output a hook echoes is captured by the worker
      and placed unescaped in the same position in the Twig template, and the
      documentation states that plugin markup is trusted.
    - Page-replacing hooks (`custom_login`, `custom_logout_message`,
      `custom_denied`, `custom_password`) are asked first. If any plugin returns
      `OPER_MODE_RESKIN`, the Symfony route hands the request to the legacy page
      (the existing `LegacyPageForwarder` pattern) instead of rendering Twig, so
      the plugin's page still appears.
    - `login_process`: a truthy return still skips core local verification, with
      the same consequences for the template and guest fallbacks as today.
    - `auth_alternate_realms` still runs on every gated request while any legacy
      page remains.
    - `logout_pre_session_destroy` runs before token revocation and
      `logout_post_session_destroy` after session teardown, as in `logout.php`.
    - `copy_user`, `user_remove`, `login_options_navigate` and the
      `auth_profile_*` hooks fire at the same points relative to the writes they
      follow.
    The migration may not add, rename or reorder a hook. A characterization test
    records each hook's call order and arguments before the first route moves.

11. **Staging and the switch.** One setting in the `settings` table, read with
    `read_config_option`, selects the implementation per entry point
    (`login`, `logout`, `changepassword`) with the default `legacy`. Both
    implementations read and write the same stored formats, so flipping the setting
    changes which code runs, not what is stored. Stages: (a) request authentication
    through the firewall with no route moved; (b) logout; (c) login with local,
    Basic, then LDAP and domains; (d) password change and the forced change;
    (e) Logout Everywhere in the profile. Each stage is its own pull request.

12. **Rollback.** Revoked state lives on the server (a new generation in
    `settings_user`, deleted `user_auth_cache` and `sessions` rows), not in code, so
    flipping the switch back to `legacy` cannot restore a revoked session or token
    and keeps every valid session working: the payload and cookie are unchanged.
    The one unsafe rollback is to application code older than the one that checks
    the `v1.` MAC and the generation; `docs/symfony-migration.md` already forbids it.
    The implementing change adds a minimum-version guard (open question 7), and
    the release notes require fresh authentication and revocation of remembered
    credentials before any such downgrade.

13. **Authorization is out of scope.** Realm and resource authorization stay with
    `ConsoleAccess`, `RealmGrants` and `ResourceAccess`. The firewall authenticates;
    it does not replace those contracts.

Exit criterion: `auth_login.php`, `login.php`, `logout.php`,
`auth_changepassword.php` and the Logout Everywhere action in `auth_profile.php`
can be deleted without losing any behavior listed in the test plan, and the
document root can move to `public/`. The second part requires that no legacy page
still includes `include/auth.php`, so it is gated on the legacy web page count in
the entry-point inventory reaching zero for gated pages (see the Twig completion
plan). Until then the legacy gate remains and the document root stays.

## Test plan

Unchanged and required at every stage (they must pass without edits):

- `tests/Unit/Security/Auth/`: `LocalLoginPolicyTest`, `LocalLoginTimingTest`,
  `LocalPasswordRehashTest`, `CredentialGenerationRehashTest`,
  `LdapLoginErrorDisclosureTest`, `LdapTlsRequirementTest`, `DomainsLoginRealmTest`,
  `LoginMutationCsrfTest`, `LogoutEverywhereTest`, `LogoutEverywhereCookieRaceTest`,
  `LogoutTokenRevocationTest`, `RememberMePasswordChangeTest`,
  `PasswordChangeEndsSessionsTest`, `ChangePasswordLockoutTest`,
  `NoAuthenticationRecoveryTest`, `BasicAuthAuthorizationTest`,
  `GuestProfileAccessTest`, the Csrf* tests.
- `tests/Symfony/`: `LegacyBrowserAuthenticationTest`,
  `NativeAuthenticationSessionTest`, `SessionCredentialBindingTest`,
  `CredentialGenerationReadTest`, `BrowserAuthenticationSqlTest`,
  `AboutAuthenticationStorageTest`, and the About HTTP scenarios.
- `session_bridge.py` with file sessions and with `--database-sessions`.
- `tests/security/entry_point_authorization.py` and the behavioral
  characterization suite (`make test-auth`). Goldens are reviewed, not re-recorded.

New, written first against the legacy path and then required to pass on both:

- **Parity matrix.** For local, Basic, LDAP and domain methods, each of: success,
  wrong password, unknown user, disabled, locked, expired password, forced
  change, template-account creation, guest fallback, no realm access. Same
  response class, same `user_log` row and result code, same `cacti_log` line.
- **Token compatibility.** A token issued by legacy restores in Symfony and the
  reverse; unversioned tokens convert; stale-generation, wrong-address and
  guest-account tokens are refused; rotation under concurrent restore.
- **Hook characterization.** Order and arguments of all 22 hooks per flow, with a
  fixture plugin, including the four reskin hooks and `login_process`.
- **Throttling.** Address bucket first, IPv4-mapped IPv6 equivalence, 429 without
  lockout, no username-bucket growth once the address bucket is exhausted, timing
  floor preserved.
- **LDAP.** Integration against a disposable OpenLDAP with TLS required and
  refused, injection strings in the username, and referral and search-failure
  cases that must not read as "no such user".
- **Switch.** Flip in both directions with live sessions and remembered tokens;
  Logout Everywhere in one mode revokes in the other; no dual write per request.
- **Revocation survives rollback.** After Logout Everywhere, flipping the switch
  leaves the old session and token invalid.
- **Failure injection.** Database failure between credential check and session
  publish; commit failure in rehash and revocation; limiter storage unavailable
  (must fail closed for the address bucket).
- **Subdirectory install, French locale through `user_auth.lang`, and CSP
  nonce coverage** on the new pages.

## Consequences

- One implementation of credential issuance and revocation replaces two, and the
  About-route duplicate disappears.
- Login, logout and password change gain Twig, translation, stateless CSRF and
  rate limiting. Per-address throttling is new behavior and is the only intended
  change in what a client observes (HTTP 429); everything else is parity.
- The firewall runs beside, not instead of, the legacy gate until the last gated
  legacy page migrates. Two code paths read the same session for a long time.
- The hook port and the reskin hand-off keep plugins working but make the login
  path depend on the legacy worker while plugins are loaded.
- The bundle adds configuration (`security.yaml`, limiter pools) and three
  dependencies to a main-only tree.
- A downgrade across the MAC and generation checks stays unsafe, and the ADR adds a
  guard rather than removing the risk.

## Alternatives

- **Symfony's `form_login` and `remember_me` as shipped.** Rejected. The remember-me
  token format, storage and rotation differ from the stored scheme and cannot
  honor the credential generation; adopting them is a forced re-login for every
  user and a revocation-semantics change.
- **Keep the legacy login and only migrate request authentication.** Viable as
  stage (a), but it leaves two issuers of credentials and blocks the exit
  criterion.
- **Stateful Symfony sessions with a bridge to the legacy payload.** Rejected. Two
  payload formats, and `docs/symfony-migration.md` already disables the session bag
  for that reason.
- **Move the stored scheme to Symfony's formats first.** Rejected for this record.
  It changes schema or token semantics and needs its own record and a migration.
- **A database-backed limiter table.** Deferred. It is a schema addition; see open
  question 4.

## Open questions

These could not be resolved from the code, with the recommendation in each case.

1. **Complete hook inventory.** Only the authentication files, `lib/auth.php` and
   `include/global.php` were searched. Hooks fired from `lib/functions.php`,
   `lib/plugins.php`, the installer or other includes during login or logout may
   exist. Recommendation: grep the whole tree and extend the inventory before
   stage (a); the hook-characterization test is the gate.
2. **Full shape of the `login_before` and `login_realms` arguments,** and whether
   a plugin may return extra keys the core reads. Recommendation: read the rest of
   both call sites and pin them in a fixture plugin test.
3. **Meaning of realm 1** and the "off by one" LDAP dropdown. Recommendation:
   characterize realm handling for methods 3 and 4 before moving LDAP.
4. **Where limiter state lives on a multi-node deployment.** A per-node filesystem
   pool weakens the limiter behind a load balancer. Recommendation: ship per-node
   first and document it; if multi-node is a supported target, add a table through
   the installer in its own change.
5. **Session idle timeout.** The `action=timeout` logout message exists, but where
   timeout is decided was not located. Recommendation: find it and keep it in the
   authenticator.
6. **Remote pollers and collectors.** Whether a non-primary `poller_id` serves
   login, and how the offline menu interacts with an unreachable main database.
   Recommendation: treat login on a collector as unsupported until traced, and
   fail closed with 503.
7. **A minimum-version guard for rollback.** Recommendation: a `settings` row
   written when the generation-aware code first runs, checked at startup by any
   code that reads credentials, so older code refuses to authenticate rather than
   accept revoked tokens. Needs a decision on the row name and on `lts/1.2`.
8. **Guest account under the firewall.** The guest is an authenticated identity
   with a real `user_auth` row and a shared session user. Recommendation: model it
   as a distinct role with an explicit "guest allowed" attribute per route, never
   as anonymous.
9. **Forced password change state** (`sess_change_password`) and the installer's
   `IN_CACTI_INSTALL` paths. Recommendation: forced change in stage (d); the
   installer stays on the legacy gate and is excluded from the exit criterion.
10. **Plugin reliance on globals** (`$current_user`, `$user_auth_realm_filenames`,
    `$_SESSION` keys). Recommendation: keep populating them from the legacy worker
    for as long as a legacy page loads plugins, and list them in the plugin API
    notes.
11. **`user_domains` and `user_domains_ldap` secrets.** How the LDAP bind password
    is stored and decrypted was not read. Recommendation: read it before stage (c)
    and keep the existing encryption.
