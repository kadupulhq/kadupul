# Explicit input for bulk permission selections

The authenticated `user_admin.php` and `user_group_admin.php` action handlers now
pass POST field names to `PermissionAssociations::apply()`. The shared helper no
longer reads `$_POST`. Only names cross this boundary; checkbox values and other
submitted values are not copied into the selection argument.

This is an architectural improvement on `main`. LTS is unchanged. It does not
retire the legacy writer or introduce a second mutation path.

## Preserved behavior

The first recognized association action still wins. Only POST field names matching
`chk_` followed by decimal digits select records, including checkboxes with an
empty value. GET fields do not contribute selections. Numeric and unrelated names
are ignored. User/group membership direction, permission types, redirects,
transaction ownership, error reporting and permission-cache invalidation stay
with the existing implementation.

The two page handlers remain behind their existing authentication, authorization
and POST/CSRF gates. They call the same association executor on the same path.
There is no route, schema, runtime, dependency or deployment configuration change.
Deploy the helper and both callers together. Rollback restores them together;
there is no new stored state to reverse. The required second argument is an
internal PHP API change; repository production callers and fixture callers are
updated, with no fallback to ambient POST.

## Verification and remaining state

The existing native filter/association harness covers both authenticated page
handlers and their rendered controls. A new regression supplies explicit selected
field names that disagree with ambient POST, then checks the persisted rows and
SQL parameters for user/group permissions and membership, additions/removals,
and empty selections. It includes malformed and numeric field names. Existing
mutation suites cover transaction rollback, caller-owned work, cache invalidation,
missing parents and failed writes. An architecture guard prevents direct globals
and superglobals from returning to the association helper.

This removes one direct superglobal dependency. The helper still uses the legacy
request helpers for the action flag, operation and validated principal ID, and
`PermissionMutation` still owns the legacy connection/transaction boundary. Those
are separate migration work. The global-declaration inventory is unchanged.
A token guard does not prove that indirect state dependencies have disappeared.

Local check counts and runtime evidence are recorded on the PR. Installed
collector/session acceptance and supported database-engine runs remain separate
checks; SQLite fixture results do not establish those contracts.
