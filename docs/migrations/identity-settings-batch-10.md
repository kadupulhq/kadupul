# Ten identity settings views

Status: implemented. This slice extends the identity relationship reader and
requires the preceding relationship and identity relationship migrations.
All paths below are under `app.php/relationships/`.

| Path | Owner | Existing PHP operation tab |
| --- | --- | --- |
| `user-realm-grants/{id}` | User | `realms` |
| `user-display-preferences/{id}` | User | `settings` |
| `user-graph-preferences/{id}` | User | `settings` |
| `user-time-preferences/{id}` | User | `settings` |
| `user-login-options/{id}` | User | `general` |
| `group-realm-grants/{id}` | Group | `realms` |
| `group-display-preferences/{id}` | Group | `settings` |
| `group-graph-preferences/{id}` | Group | `settings` |
| `group-time-preferences/{id}` | Group | `settings` |
| `group-login-options/{id}` | Group | `general` |

The native user/group catalogs link to these views and retain their existing
edit links. Console realm 8 and user administration realm 1 are required before
filter parsing or owner lookup. Disabled target accounts/groups can still be
inspected by an authorized administrator; the current viewer must satisfy the
existing enabled, unlocked, password-change and direct/enabled-group grant rules.

## Stored data and compatibility

Realm pages show recorded permission realm IDs from `user_auth_realm` or
`user_auth_group_realm`. They retain zero and unknown/plugin realm IDs without
inventing labels or calculating effective grants. Authentication realm values
on account records are a separate contract and are not substituted here.

Display, graph and time pages read explicit public keys from `settings_user`
or `settings_user_group`. The fixed allowlists come from the existing
`settings_user` definitions in `include/global_settings.php`. Display includes
theme, language, default view, horizontal scrolling and refresh. Graph settings
include titles, disabled/aggregate visibility, thumbnails and tree preferences.
Time settings include timezone/date formatting, ranges, shifts and weekdays.
Plugin-defined and unrecognized keys, including credential-like settings, are
excluded before searching or returning rows.

Login pages read only these account/group columns: enabled state, login option
and permission to change graph settings; user pages also show lock state,
mandatory password change and permission to change the password. Password
hashes, history, remember-me material and security generation metadata are not
projected. A nullable setting remains null rather than becoming an empty string.

These pages show stored values, not effective configuration. An absent override
produces no preference row; no server/group default is synthesized. Explicit
empty strings display as “Empty string,” null login fields as “Not set,” and
zero remains zero. Enum values and setting keys retain their recorded bytes.
Friendly labels use the viewer's locale, not the target owner's language.
Search matches stored setting keys and values; realm search matches recorded IDs.
Sorting uses the database's collation with a stable identity tiebreaker.

Each reader uses one owner lookup and one bounded child query. Existing validated
list filters cap pages at 99999 and row choices at 10/25/50/100, with one lookahead
row. Parent identity, installation subdirectory and front-controller paths are
preserved in filters and pagination. All database text is escaped in Twig;
settings are neither evaluated nor applied to the viewer.

## Deployment and rollback

Deploy after the prerequisite identity relationship slice. No schema, dependency
or runtime upgrade is needed. Deploy source, Twig templates and English/French
catalogs together and rebuild normal Symfony caches/assets. Rollback restores
the prerequisite application revision and rebuilds its caches/assets. There are
no new writes, queued operations or persisted migration markers. Existing PHP
forms, preference inheritance and plugin behavior remain active.

## Evidence and release limits

Kernel regressions cover all thirty relationship views, authorization before
malformed input, escaped output, missing/empty/failed reads, GET/HEAD/POST behavior,
locale selection and subdirectory links. New DOM assertions distinguish zero,
empty and null settings and check friendly labels alongside machine keys.

Native MariaDB regressions load the shipped DDL into disposable schemas. They
exercise all ten readers and sort directions, owner separation, allowlisted
projection/search, unknown realm IDs, empty/missing overrides, nullable fields,
large group IDs, stable pagination and the two-query budget. Existing native
admission tests also cover the new views. The new class runs in the existing
six-engine CI matrix. Browser tests render each new view in all seven themes
under the actual response CSP, with keyboard navigation and GET filter submission.

Installed-session and collector HTTP acceptance remain release checks. Browser
admission/data adapters are fixtures. Local checks do not verify legacy saves,
production deployment or external devices. This slice does not retire legacy
writers or globals and does not change effective authorization.
