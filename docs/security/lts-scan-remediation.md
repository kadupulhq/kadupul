# LTS scanner baseline and remediation

The authoritative LTS project is `kadupulhq_kadupul-lts`, not the stale
`lts/1.2` branch entry in the main Sonar project. At branch revision
`a2bc3dfe17185e4dd1469e7efed187dfe7afc7f9`, its scan of 2026-09-20 reports
495 unresolved vulnerabilities. GitHub code scanning lists zero for this branch;
that does not mean LTS is free of findings.

| Category | Findings |
|---|---:|
| XSS | 417 |
| Weak randomness | 26 |
| Weak hashing | 16 |
| Path injection | 12 |
| Command injection | 6 |
| Redirects | 4 |
| File permissions | 4 |
| Log injection | 3 |
| Cleartext protocols | 2 |
| SQL, LDAP, template injection, LDAP authentication, hardcoded credential | 5 |

Comparison with main revision `5b704fdf3c5365dd9eaaeb1130d443d7e2aa28d9`
found 459 LTS findings sharing a rule/file/sink-hash fingerprint with main's
481 findings. These are candidate overlaps, not proof of identical exploitability
or source-to-sink behavior. LTS findings are tracked separately by their own keys.

## First batch

- `AaCs2O-zIug_wyaLilo8`: replace the host-reindex shell invocation with argv.
- `AaCs2POKIug_wyaLilrR`: replace input-whitelist shell invocation with argv.
- Retain LTS routes, existing CSRF guards, reindex advisory locking/shutdown
  release, translated output and synchronous execution. Report worker failure as
  failure instead of success. Do not raise PHP's 8.0 runtime floor.
- Add explicit null-timeout completion mode to the existing shared executor.
  Preserve its 30-second default and false/four-hour mode. Normalize select
  timeout seconds/microseconds and use a monotonic deadline. Timed termination
  controls only the direct process, not an entire process tree.
- Regression tests cover successful/failed/busy controllers, lock release,
  escaped output, real subprocess status, literal metacharacters and timeouts.

These are fixes proposed for scanner-confirmed unsafe boundaries; existing
escaping alone is not evidence that every scanner path was exploitable. Mark
findings closed only when a fresh merged-LTS analysis confirms closure.

## Remaining batches

### Verified first-batch closure

The 2026-09-21T02:13:05Z analysis of merged revision
`b3cf69a9a14b1d9a84df7aa0652a3dba98eeaed1` reports 493 unresolved
vulnerabilities, down from 495. Both first-batch keys above are `CLOSED` with
resolution `FIXED` in Sonar. The original category table remains a historical
baseline, not the current count.

### Installer PHP probe

- Target `AaCs2NkYIug_wyaLilnG`: replace the installer PHP probe's shell string
  with a direct executable and separate argv elements.
- Preserve the operator-selected executable, existing path checks, random
  numeric challenge, synchronous wait and PHP 8.0 runtime floor. Do not import
  main's executable allowlist policy into LTS.
- Require both a successful worker exit and the expected square result before
  saving the executable configuration.
- Execute the production method in regression tests with stubbed external
  boundaries; cover success, wrong output, nonzero exit with correct output,
  spawn failure and missing executable. The shared executor suite separately
  exercises real subprocesses and literal metacharacters.
- Treat this finding as pending until a merged-LTS scan confirms closure.

Inspect realtime polling, installer PHP probes, background remote-discovery
arguments and SQL save callers next. Preserve asynchronous worker behavior and
database connection semantics. Then address XSS by output context and controller;
do not globally escape helpers that intentionally return markup. Handle other
controls according to actual use, and document individual false positives in the
scanner rather than blanket-dismissing alerts. This baseline is not a claim that
all 495 findings have been fixed or independently reproduced.
