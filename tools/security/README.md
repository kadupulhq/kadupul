# Security inventory and remediation

Run the read-only collector with authenticated GitHub CLI access:

```sh
mise exec node@22.22.2 -- node tools/security/inventory.mjs /absolute/path/to/private-report
mise exec node@22.22.2 -- node --test tests/Unit/security-inventory.test.mjs
```

The collector paginates both scanners, requires matching main revisions for
Sonar-linked alerts (other scanners retain their own recorded revisions), and
matches GitHub alerts using their embedded stable Sonar issue keys. It retains
unmatched alerts rather than silently treating similar locations as duplicates.
It writes a normalized `inventory.json` with every finding and a grouped,
provisionally ranked `TRIAGE.md`. These are snapshots, not proof of exploitability
or closure. Keep reports outside the repository and refresh after merged scans.

Initial baseline: main `5b704fdf3c5365dd9eaaeb1130d443d7e2aa28d9` has 481
unresolved Sonar vulnerabilities; all 150 open GitHub alerts match those findings.
The full set contains 407 XSS, 24 weak-randomness, 15 weak-hash, 11 path-injection,
7 command-injection, 4 redirect, 4 permission, 3 log-injection, and 6 other findings.

## Ordered fix batches

1. Device reindex: remove the shell sink, enforce CSRF-protected POST, validate
   positive IDs, preserve synchronous execution, and distinguish worker failures.
   Target Sonar key: `AaCs2egyFv5APcRNUC1c`. Existing escaping means the scanner's
   command-injection finding is not itself proof of exploitation; eliminating
   shell interpretation removes that boundary entirely. GET mutation is confirmed.
2. Remote-agent command flows and SQL helper callers: inspect authentication,
   input validation and each caller before changing shared execution/database
   helpers. Preserve asynchronous worker semantics; do not blanket-dismiss flows
   that appear integer-validated. Inspect the embedded-secret finding in this batch.
3. XSS: group by controller and rendering helper, identify HTML text/attribute,
   URL and JavaScript contexts, and add payload-based regression cases before
   introducing context-appropriate escaping. Do not globally escape trusted markup.
4. Path/LDAP/template injection, redirects, transport/cookie/permission controls,
   followed by randomness, hashing and logging findings after checking their uses.

This order is provisional: confirmed unauthenticated or privileged exploitation
supersedes the rule-based ranking. Scanner counts do not cover the separate manual
authentication/authorization and CSRF audit. LTS changes require separate
compatibility assessment; these batches target main only.

Each focused PR needs regression evidence, native review-thread replies and
re-review, passing CI/quality gates, and a fresh main scan confirming closure.
No bulk dismissals or exclusions are part of this workflow.
