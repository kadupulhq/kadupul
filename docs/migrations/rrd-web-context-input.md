# Explicit timezone input at the RRDtool boundary

`LegacyRrdWebContext::prepareProcess()` now receives the web flag and timezone
value explicitly. It no longer reads `$_COOKIE` or accepts the whole application
configuration array. The procedural `__rrd_execute()` compatibility boundary reads
the current cookie only for web execution and passes the values immediately before
starting the local one-shot RRDtool process.

This is an architectural improvement on `main`; LTS is unchanged. It removes one
adapter's direct superglobal dependency. The declaration ratchet is unchanged
because the removed reads were superglobal accesses, not `global` declarations.
The procedural caller still owns legacy configuration and request integration.

## Compatibility

Session release remains before the executable check. Timezone preparation remains
after that check and before process startup. Persistent/acknowledged pipes, remote
proxy dispatch, timeouts, output modes, command bytes and error handling retain
their existing paths. No new write path or process is introduced.

The legacy `is_web` flag keeps its PHP truthiness at the procedural boundary.
Missing and null cookies cause no timezone call. Numeric zero and string zero are
forwarded, and CLI execution does not apply a cookie. Other non-null values retain
the existing timezone helper's behavior; this change does not add or claim cookie
validation, timezone-policy changes or a timezone reset when no cookie is supplied.
`cacti_time_zone_set()` still owns browser opt-in, offset conversion and timezone
side effects. `cacti_session_close()` still owns the session lifecycle.

The same cached context object can serve successive calls because it does not
store a viewer or timezone. Each call receives its own explicit values. No HTTP
Request or session object enters application/domain code through this change.

## Verification

The context test supplies offsets that disagree with ambient cookies and reuses
the object for successive viewers, CLI mode, null and both zero representations.
The native no-shell harness executes the production RRDtool dispatch path with a
fixture executable. Web, missing/null/zero-cookie and CLI cases assert the exact
session-close, timezone-handoff and child-start sequence. Existing process tests
cover command/response framing, output modes, nonzero exits, deadlines and cleanup.
No production RRD files or devices are used.

The existing context/no-shell test files are registered in the explicit legacy
suite. The one-shot coverage registration now requires the context source as well
as the dispatcher and transport. The tagged no-shell collector also includes that
source, and its reports now verify producer/scenario hashes, source hashes and
completion markers before merging. An unrelated permission-filter producer is
checked to ensure the default collector remains compatible. An architecture guard rejects direct globals/superglobals in the context.
Check counts, execution coverage and environment limitations are recorded on the PR.

Installed web-session and collector acceptance remain separate checks. Fixture
observations establish this handoff, not every timezone policy or platform path.

## Deployment and rollback

Deploy `lib/rrd.php` and `LegacyRrdWebContext.php` together: the adapter's internal
method now requires `bool $isWeb, mixed $timezone`. There is no ambient-cookie
fallback for an old caller. All repository production/test callers are updated.
Rollback restores both files together. No schema, dependency, route, runtime or
persistent-data change is required; this slice introduces no state to restore.
