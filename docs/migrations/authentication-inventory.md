# Authentication surface inventory

Measured on `origin/main` at `6bd6a540ba` by reading the files named below. It
supports [ADR 0010](../adr/0010-symfony-security-authentication.md). Nothing here
was executed; every statement is from source. The hook list covers the
authentication files and `lib/auth.php` only (see "Not covered").

## Entry points

| File | Role | Notes |
| --- | --- | --- |
| `login.php` (15 lines) | Compatibility entry that requires `include/auth.php`, then redirects to `index.php` with 303 | `index.php` now belongs to Symfony |
| `auth_login.php` (469) | Login form and credential processing | Requires a validated POST for every method except Basic (`cacti_require_post_actions(['login'])`) |
| `logout.php` (87) | Hooks, remembered-token revocation, cookie and session teardown, redirect | Reachable by GET without a CSRF token; the file records this |
| `auth_changepassword.php` (500) | Password change, including the forced change | Reachable by an eligible user without a realm check (`include/auth.php`) |
| `auth_profile.php` (684) | Profile tabs and the Logout Everywhere action | `logout_everywhere` is a POST action that calls `auth_logout_everywhere()` |
| `user_domains.php` (775) | Administration of LDAP domains (`user_domains`, `user_domains_ldap`) | Administration, not a login path |
| `include/auth.php` (363) | The gate included by protected legacy pages | See below |
| `lib/auth.php` (6536) | Credential, lockout, LDAP, domain, permission and session-binding functions | |
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

## Credentials and sessions

**Password hashes.** Verified through `compat_password_verify`; a hash that
`compat_password_needs_rehash` flags is rewritten at login by
`auth_rehash_password_preserving_sessions()` in one transaction that also keeps
the credential generation (`lib/auth.php:6186`).

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

**Logout Everywhere.** `auth_logout_everywhere()` (`lib/auth.php:6042`), in one
transaction on the sessions connection: requires InnoDB for `user_auth`,
`settings_user`, `user_auth_cache` and `sessions` (MySQL), locks the account row,
checks the presented generation is still current, writes a new generation, reads
it back, deletes the user's `user_auth_cache` and `sessions` rows, commits. Any
failure rolls back and the caller returns HTTP 503. The browser is logged out only
after the commit.

**Auth transition.** `cacti_auth_transition($user_id, $reason)` rechecks that the
account is enabled and unlocked (the guest may be disabled), regenerates the
session id, clears cached permissions and binds the credential generation.
Reasons in use: `login`, `cookie_restore`, `basic_auth`, `guest`.
`cookie_restore` keeps the generation that admitted the token.

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
Symfony's own session bag is disabled. `symfony/security-core`, `security-csrf`
and `password-hasher` are installed; `symfony/security-bundle`, `symfony/ldap` and
`symfony/rate-limiter` are not, and `config/packages` has no `security.yaml`.

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
| `custom_login` | fn | `auth_login.php:302` | `OPER_MODE_NATIVE`; `OPER_MODE_RESKIN` means the plugin rendered the page |
| `login_title` | fn | `auth_login.php:312` | the default title string |
| `login_before` | fn | `auth_login.php:322` | array with `error`, `error_msg`, `username` and further keys not read here; output is echoed inside the form |
| `login_realms` | fn | `auth_login.php:356` | realm array keyed by realm id with `name` and `selected`; LDAP method only |
| `login_after` | action | `auth_login.php:411` | none; output is echoed |
| `login_process` | fn | `lib/auth.php:4473` | `false`; a truthy return skips core local verification |
| `login_options_navigate` | fn | `lib/auth.php:5751` | the user's `login_opts`; reached in the default branch of the post-login redirect |
| `logout_pre_session_destroy` | action | `logout.php:16` | none; runs before token revocation |
| `logout_post_session_destroy` | action | `logout.php:33` | none |
| `custom_logout_message` | fn | `logout.php:36` | `OPER_MODE_NATIVE`; `OPER_MODE_RESKIN` means the plugin rendered the page |
| `custom_password` | fn | `auth_changepassword.php:278` | `OPER_MODE_NATIVE`; `OPER_MODE_RESKIN` as above |
| `change_password_title` | fn | `auth_changepassword.php:370` | the default title string |
| `auth_profile_update_data` | fn | `auth_profile.php:43` | the current tab |
| `auth_profile_tabs` | fn | `auth_profile.php:66` | the tab array |
| `auth_profile_run_action` | fn | `auth_profile.php:93` | the requested tab |
| `auth_profile_reset` | action | `auth_profile.php:131` | none |
| `auth_profile_reset_value` | fn | `auth_profile.php:180` | the setting name |
| `auth_profile_save` | action | `auth_profile.php:255` | none |
| `copy_user` | fn | `lib/auth.php:600` | the copy hook data, after a successful copy |
| `user_remove` | fn | `lib/auth.php:714` | the user id |

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

## Not covered

- Hooks fired outside the files above (for example from `lib/functions.php`,
  `lib/plugins.php` or the installer) were not searched.
- Only the beginning of `login_before`'s argument array was read.
- `install/` authentication, remote-poller (`poller_id` other than 1) behavior,
  and session idle-timeout enforcement were not traced.
- Plugin code was not inspected.
