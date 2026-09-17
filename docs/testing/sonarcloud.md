# PHP Sonar analysis

The `kadupulhq_kadupul` and `kadupulhq_kadupul-lts` projects use the
project-specific **Kadupul PHP safety and conventions** quality profile.
It combines Sonar way core with all 20 rules from the previous PSR-2 profile
(116 active rules when configured). The organization default remains unchanged.

Function naming rule `php:S100` uses `^_*[a-z][a-zA-Z0-9_]*$` to accept
the documented snake_case procedural helpers and existing camelCase helpers.
No reliability or security rule is disabled for naming compatibility.

After changing a profile, analyze the base branch and the PR again. A result
from the former style-only profile does not demonstrate that the core safety
rules passed. Record newly exposed pre-existing issues separately from changes
introduced by a PR. Inspect taint flows and caller authorization before
classifying a security finding; a passing style gate is insufficient evidence.

Credentials come from runtime secrets or Keychain, never this repository.
