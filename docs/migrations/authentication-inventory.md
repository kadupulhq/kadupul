# Authentication surface inventory

Measured on `origin/main` at `6bd6a540ba` by reading the files named below. It
supports [ADR 0010](../adr/0010-symfony-security-authentication.md). The original inventory was source-only. Later local migration evidence is
identified below and in the implementation record. The hook list covers the
authentication files, `lib/auth.php`, `lib/ldap.php`, `include/session.php` and
the helpers in `lib/functions.php` that the login flow calls (see "Not covered").
`AuthenticationHookInventoryTest` pins the list.

## Entry points

| File | Role | Notes |
| --- | --- | --- |
| `login.php` (15 lines) | Compatibility entry that requires `include/auth.php`, then redirects to `index.php` with 303 | `index.php` now belongs to Symfony |
| `auth_login.php` (380) | Native credential processing and hooks; form sections rendered by the injected Twig service | Requires a validated POST for every method except Basic (`cacti_require_post_actions(['login'])`) |
| `logout.php` (102) | Symfony request validation and confirmation/message rendering; POST retains native hooks, remembered-token revocation, cookie/session teardown and redirect | GET/HEAD require confirmation; only a native-CSRF-validated POST runs teardown. `/logout/message` renders only an in-process presentation object and cannot perform logout |
| `auth_changepassword.php` (500) | Password change, including the forced change | Reachable by an eligible user without a realm check (`include/auth.php`) |
| `auth_profile.php` (684) | Profile tabs and the Logout Everywhere action | `logout_everywhere` is a POST action that calls `auth_logout_everywhere()` |
| `user_domains.php` (775) | Administration of LDAP domains (`user_domains`, `user_domains_ldap`) | Administration, not a login path |
| `include/auth.php` (363) | The gate included by protected legacy pages | See below |
| `lib/auth.php` (6362) | Credential, lockout, LDAP, domain, permission and session-binding functions | |
| `include/session.php` (135) | Database session handler, loaded when configured | Skipped for CLI |
| `include/csrf.php` (404) | csrf-magic bootstrap, secret loading, cross-site refusal | |
| `public/index.php` | Symfony front controller | The target docroot |

### The `include/auth.php` gate

Every protected legacy page includes it. In order it: reads `auth_method`;
applies the no-authentication recovery (`check_reset_no_authentication`);
redirects to `install/` on a version mismatch; returns early for `logout.php`;
rechecks session eligibility (`auth_session_check_eligibility`, HTTP 403 or the
login page); returns early for `auth_changepassword.php`; fires
`auth_alternate_realms`; routes a pending forced password change; restores a
remembered login; performs Basic authentication; handles the guest account;
renders the login page when there is no user; then checks the page's realm
against `user_auth_realm` and the group tables, firing `custom_denied` and
rendering the permission-denied page on refusal.

Consequence for the exit criterion: while any legacy page remains, this gate is
still needed. Deleting the login and logout entry points does not delete it.

## Authentication methods and realms

`auth_method` setting: 0 none (refused; recovery only), 1 local, 2 Basic (web
server), 3 LDAP, 4 LDAP domains. Account realm values seen in the code: 0 local,
2 Basic, 3 LDAP, and `1000 + domain_id` for a domain. The login form's realm
dropdown is "off by one" for LDAP (`auth_login.php` compensates), and for
methods 3 and 4 choosing "local" switches the method to 1. The meaning of realm
1 beyond that dropdown was not established (see ADR open questions).

**Basic (method 2) and the web server.** The username is read only from
`REMOTE_USER`, then `REDIRECT_REMOTE_USER`, as the web server sets them after it
has checked the password. It is never read from `PHP_AUTH_USER`, which PHP fills
from the client's `Authorization` header. A setup that passes only
`PHP_AUTH_USER` to PHP now answers "no username was passed" until the server
also passes `REMOTE_USER`.

- nginx: `auth_basic` sets `$remote_user` ("user name supplied with the Basic
  authentication"). Pass it to PHP-FPM with `fastcgi_param REMOTE_USER
  $remote_user;` in the `location` that handles PHP, because the stock
  `fastcgi_params` file does not include it.
- Apache: `REMOTE_USER` is a standard CGI meta-variable that Apache makes
  available to CGI-style handlers. Check that it reaches PHP under your handler
  (`mod_php`, `mod_proxy_fcgi`) by looking for `REMOTE_USER` in `phpinfo()` after
  signing in; the Apache documentation does not state this per handler.
- `lts/1.2` keeps reading `PHP_AUTH_USER` as a last resort, after the two
  server variables, so 1.2 setups that pass only the header keep working.

## Credentials and sessions

**Password hashes.** The three native `compat_password_*` helpers now share
`LegacyPasswordHasher`, registered with Symfony as `legacy_native`. Verification
retains native bytes, existing MD5 support and default-cost padding. Hashing and
rehash checks retain the selected PHP algorithm/options. A hash that
`compat_password_needs_rehash` flags is rewritten at login by
`auth_rehash_password_preserving_sessions()` in one transaction that also keeps
the credential generation (`lib/auth.php:6025`).

**Credential generation.** `settings_user` row named `auth_credential_generation`
holds `<sha256(password hash)>:<64 hex>`. `auth_session_credential_generation()`
returns the 64-hex part only while the first part matches the stored password
hash; otherwise it returns `sha256(password hash)`, so a replaced password
invalidates every binding. Sessions store the value as `sess_user_credential` and
are rechecked on every request (`auth_session_credentials_valid`).

**Remembered login.** Cookie `cacti_remembers`, value `userid,realm,token`
(older forms: two parts, or a username in place of the id). The server stores
`sha512(token)` in `user_auth_cache(user_id, hostname, last_update, token)`.
Restore requires an enabled, unlocked account of the stated realm, the token
hash, the same client address as stored, a passing lockout check, and, for a
versioned token, a matching MAC. Restore rotates: the old row is deleted and a
new token is issued. Versioned tokens are `v1.<64 hex>.<hmac_sha256(64 hex,
generation)>`; an unversioned token is accepted until revoked and is converted
on restore. `auth_cache_enabled` must be `on`. Tokens for the guest account are
refused.

**Logout Everywhere.** `auth_logout_everywhere()` (`lib/auth.php:5937`) delegates
to `NativeSessionRevocation`, also injectable through `DatabaseConnection`. In one
transaction on the resolved sessions connection it: requires InnoDB for `user_auth`,
`settings_user`, `user_auth_cache` and `sessions` (MySQL), locks the account row,
checks the presented generation is still current, writes a new generation, reads
it back, deletes the user's `user_auth_cache` and `sessions` rows, commits. Any
failure rolls back and the caller returns HTTP 503. The browser is logged out only
after the commit.

**Auth transition.** `cacti_auth_transition($user_id, $reason)` is now a thin
compatibility adapter to `TransitionAuthenticationSession`. The application
use case rechecks that the account is enabled and unlocked (the guest may be
disabled), validates the remembered-login credential snapshot, and orders
session rotation, permission-cache clearing and credential binding. The native
adapter still performs PHP session operations and legacy logging. Form login,
Basic login, remembered-login restore and guest entry all retain the same
compatibility facade. The authenticated user ID is written only after a
successful transition. Reasons in use: `login`, `cookie_restore`, `basic_auth`,
`guest`. `cookie_restore` keeps the generation that admitted the token.

**Lockout.** Per `username` and `realm`, not per address: `secpass_lockfailed`
(attempt limit), `secpass_unlocktime` (minutes, capped at 1440), enforced by
`auth_checkclear_lockout`, `auth_process_lockout_check`, `auth_process_lockout`.
A search of `lib/`, `include/`, `src/` and `auth_login.php` found no per-address
throttle.

**Timing.** `local_auth_login_process()` holds every local attempt to a floor of
`auth_login_timing_floor_ms` (1000 ms default and minimum, 60000 maximum).

**Login audit.** `user_log(username, user_id, result, ip, time)`: result 0 failed,
1 password or Basic, 2 remembered cookie. Messages also go to `cacti_log` with the
`AUTH` facility.

**Session storage.** PHP session named `Cacti` (`include/global.php:60`), cookie
`HttpOnly`, `SameSite=Strict`, path `url_path`. Files by default; the `sessions`
table (`id, remote_addr, access, data, user_id, user_agent, start_time,
transactions`) when `include/session.php` is loaded. Payload keys that matter:
`sess_user_id`, `sess_user_credential`, `sess_change_password`,
`sess_remember_token`, `sess_cookie_credential`, `sess_user_language`. Plugins
read `$_SESSION['sess_user_id']`.

**Symfony side today.** `IdentityAccess` already has a bridge that reads the same
session and rechecks the account: `SharedSession`, `NativeAuthenticationSession`
(establish, publish, revoke), `LegacyBrowserAuthentication` (Basic and remembered
restore for the About route), `ReadOnlyDatabaseSessionHandler`, the
`AuthenticationFileSessionHandler` and `AuthenticationDatabaseSessionHandler`.
Symfony's own session bag is disabled. SecurityBundle, Security HTTP/Core, CSRF
and PasswordHasher are installed. `config/packages/security.yaml` configures the
three-route native session firewall and the `legacy_native` password hasher.
Symfony LDAP and RateLimiter remain uninstalled pending their consumers.

## CSRF

Legacy pages use csrf-magic (`include/csrf.php`, `include/vendor/csrf/`) with a
secret loaded by `cacti_csrf_load_secret()`, a malformed-token pre-check, a
cross-site refusal for page flags and GET, and `cacti_csrf_rotate()` at
boundaries. Symfony pages use stateless CSRF that needs the form token and
same-origin browser evidence (`docs/symfony-migration.md`).

## Plugin hooks

Names and call shapes read from source. "fn" is `api_plugin_hook_function` (value
returned to core); "action" is `api_plugin_hook` (no return value used).

| Hook | Kind | Where | Argument and use |
| --- | --- | --- | --- |
| `auth_alternate_realms` | fn | `include/auth.php:58` | none; runs on every gated request when `auth_method != 0` |
| `custom_denied` | fn | `include/auth.php:304` | `OPER_MODE_NATIVE`; `OPER_MODE_RESKIN` means the plugin rendered the page |
| `custom_login` | fn | `auth_login.php:315` | `OPER_MODE_NATIVE`; `OPER_MODE_RESKIN` means the plugin rendered the page |
| `login_title` | fn | `auth_login.php:329` | the default title string |
| `login_before` | fn | `auth_login.php:331` | array with `error`, `error_msg`, `username` and further keys not read here; output is echoed inside the form |
| `login_realms` | fn | `auth_login.php:349` | realm array keyed by realm id with `name` and `selected`; LDAP method only |
| `login_after` | action | `auth_login.php:382` | none; output is echoed |
| `login_process` | fn | `lib/auth.php:4463` | `false`; a truthy return skips core local verification |
| `login_options_navigate` | fn | `lib/auth.php:5633` | the user's `login_opts`; reached in the default branch of the post-login redirect |
| `logout_pre_session_destroy` | action | `logout.php:33` | none; runs after POST/CSRF validation and before token revocation |
| `logout_post_session_destroy` | action | `logout.php:40` | none; presentation reads any changed action afterward |
| `custom_logout_message` | fn | `logout.php:42` | `OPER_MODE_NATIVE`; `OPER_MODE_RESKIN` means the plugin rendered the page |
| `custom_password` | fn | `auth_changepassword.php:278` | `OPER_MODE_NATIVE`; `OPER_MODE_RESKIN` as above |
| `change_password_title` | fn | `auth_changepassword.php:370` | the default title string |
| `auth_profile_update_data` | fn | `auth_profile.php:43` | the current tab |
| `auth_profile_tabs` | fn | `auth_profile.php:66` | the tab array |
| `auth_profile_run_action` | fn | `auth_profile.php:93` | the requested tab |
| `auth_profile_reset` | action | `auth_profile.php:131` | none |
| `auth_profile_reset_value` | fn | `auth_profile.php:180` | the setting name |
| `auth_profile_save` | action | `auth_profile.php:255` | none |
| `copy_user` | fn | `lib/auth.php:604` | the copy hook data, after a successful copy |
| `get_template_account` | fn | `lib/functions.php:3447` | the username being created from a template; returning a different value replaces the template account |
| `nav_login_before` | action | `lib/functions.php:4265` | none; runs before the guest user menu is printed |
| `nav_login_after` | action | `lib/functions.php:4274` | none; runs after the guest user menu is printed |
| `user_remove` | fn | `lib/auth.php:718` | the user id |

These are part of the public plugin API under [VERSIONING.md](../../VERSIONING.md):
removing or renaming one, or changing what it is passed, forces a major release.

## Existing tests that cover this surface

`tests/Unit/Security/Auth/`: `LocalLoginPolicyTest`, `LocalLoginTimingTest`,
`LocalPasswordRehashTest`, `CredentialGenerationRehashTest`,
`LdapLoginErrorDisclosureTest`, `LdapTlsRequirementTest`, `DomainsLoginRealmTest`,
`LoginMutationCsrfTest`, `LoginPageRefreshTest`, `LogoutEverywhereTest`,
`LogoutEverywhereCookieRaceTest`, `LogoutTokenRevocationTest`,
`RememberMePasswordChangeTest`, `PasswordChangeEndsSessionsTest`,
`ChangePasswordLockoutTest`, `NoAuthenticationRecoveryTest`,
`BasicAuthAuthorizationTest`, `GuestProfileAccessTest`, `AuthCookieNativeCoverageTest`,
`AuthControllerNativeCoverageTest`, `CsrfRotationConcurrencyTest`, the Csrf* tests
and the permission tests beside them.

`tests/Symfony/`: `LegacyBrowserAuthenticationTest`, `NativeAuthenticationSessionTest`,
`SessionCredentialBindingTest`, `CredentialGenerationReadTest`,
`BrowserAuthenticationSqlTest`, `AboutAuthenticationStorageTest`,
`about_authentication_review_http.py`, `about_native_authentication.py`,
`session_bridge.py` (file and `--database-sessions`).

Other: `tests/security/entry_point_authorization.py`, and the behavioral
characterization suite (`make test-auth`).

## Resolved by reading the source

- **Hook coverage.** A search of the whole tree for hook names beginning
  `login`, `logout`, `auth`, `custom_`, `change_password` or `user_` found no call
  site outside the files above. The `user_admin_*` and `user_group_admin_*` hooks
  belong to user administration, not to authentication. `lib/plugins.php`,
  `lib/html.php` and `include/global.php` fire none of the authentication hooks.
  The three helper hooks in the table were found by this search.
- **`login_before` argument.** An array of exactly `error`, `error_msg`,
  `username`, `user_enabled` and `action`; the return value is not used, only the
  output the plugin prints inside the form.
- **`login_realms` argument.** Realm `1` is Local (not selected) and realm `2` is
  LDAP (selected). Choosing 1 or 0 under methods 3 and 4 switches the method to
  local; choosing 2 is compensated to realm 3. Only method 3 calls the hook;
  method 4 uses `get_auth_realms(true)`.
- **Session idle timeout.** There is no application timer. A session ends when PHP
  garbage-collects it after `session.gc_maxlifetime` (the database handler checks
  the same value). `logout.php?action=timeout` now selects a confirmation reason;
  the gate still redirects there when the session has gone. A validated POST
  performs teardown and shows the timeout message. The authenticator must therefore
  keep the session lifetime as it is and not add a second timer.

## Shared native lockout check

`auth_process_lockout_check()` now delegates to `IsAccountLocked` through
`NativeAccountLockoutStatus`. Local, LDAP, domain and remembered-token callers
retain their original ordering and error reporting. Disabled policy skips the
lookup; enabled policy reads the current enabled/locked account in its exact realm.
The facade preserves an existing caller error when unlocked and sets the native
translated error when locked. Failure counting and timed unlocking remain native.
See the [implementation record](symfony-security-authentication.md) for native
characterization, execution coverage, persistence limits and rollout/rollback.

## Shared complexity implementation

`secpass_check_pass()` now delegates to `NativePasswordComplexity`, backed by the
pure `PasswordComplexity` domain rules. Login policy, password changes and both
administrator check/save paths share that facade. Settings-read order, byte and
ASCII semantics, native comparisons and gettext identities remain compatible.
The [implementation record](symfony-security-authentication.md) records 3,969
original-helper comparisons, native persistence tests and catalog verification.

## Policy after password verification

`secpass_login_process()` delegates its post-verification stage to
`ApplyLocalLoginPolicy` through `NativeLocalLoginPolicy`. The application command
coordinates forced-change permission and optional activity recording. The adapter
retains settings, the native complexity checker, local enabled-account SQL,
translated notification/denial and logging. Lockout and credential verification
still happen before delegation; the caller still owns session eligibility and
issuance. Fourteen compatibility scenarios match the preceding native flow. See
the [implementation record](symfony-security-authentication.md) for measured
coverage, persistence limits and rollout/rollback.

## Local credential application boundary

`local_auth_login_process()` retains the plugin hook and elapsed timing floor,
then delegates to `VerifyLocalCredentials` through `NativeLocalCredentials`.
The application service coordinates one verification, fresh local-account hash
confirmation and optional rehash. Native policy, messages, rehash transaction and
session issuance retain their existing helpers. The adapter reads the submitted
password again only when rehashing is required. No new HTTP route or write path
exists. See the [implementation record](symfony-security-authentication.md) for
compatibility evidence and the remaining policy/issuer migration.

## Login presentation migration

`LoginPageRenderer` renders named Twig sections between the original native
hooks. Hook arguments, echoed output, mutation of later display values and early
plugin exit stay in native scope. `custom_login` reskin mode returns before
Symfony boots. The existing entry still owns authentication, credential writes,
realm selection, native headers/footer and csrf-magic insertion. The form keeps
its relative target, method and control names. No new HTTP login route exists.

Local characterization covers the original and migrated form DOM in 13 scenarios,
including native entity handling, realm options, translated fixture labels, hook
changes and early exits. Four browser cases execute the migrated script under
the native default/nonce CSP policies. This does not establish installed plugin,
LDAP or user-preference locale behavior. See the
[implementation record](symfony-security-authentication.md) for verification.

## Not covered

- `install/` authentication, remote-poller (`poller_id` other than 1) behavior,
  and the storage of the `user_domains_ldap` bind password were not traced.
- Plugin code was not inspected.
