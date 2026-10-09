# OpenSSF Best Practices enrollment evidence

This is an evidence inventory for a maintainer completing the
[passing-badge assessment](https://www.bestpractices.dev/en/criteria/0).
The canonical repository is enrolled as
[project 15322](https://www.bestpractices.dev/en/projects/15322). On 2026-10-09,
the saved passing assessment reached 70%. This is an assessment in progress,
not a passing badge or security certification.

The primary maintainer confirmed secure-design and common-vulnerability
knowledge, acknowledgement of all vulnerability reports within 14 days over
the last six months, no valid unrotated repository credentials, and no publicly
known medium-or-higher vulnerabilities left unfixed for more than 60 days.
These entries are maintainer attestations, not independent audit conclusions.

| Area | Repository evidence | Still to verify |
| --- | --- | --- |
| Purpose and contribution process | `README.md`, `CONTRIBUTING.md`, public PRs and issue templates | Verify public documentation links and install instructions on the release revision |
| License and public history | `LICENSE`, public Git repository, signed-off commits | Confirm notices for all distributed dependencies |
| Versioning and release notes | `CHANGELOG.md`, version-tag release workflow | First supported release and its complete notes |
| Bug and enhancement reporting | Public GitHub issues and templates | Assess actual response history; do not infer it from configuration |
| Private vulnerability reporting | `SECURITY.md`, GitHub Security Advisories | Confirm actual acknowledgement times and any outstanding reports privately |
| Builds and tests | Locked Composer/npm dependencies, `Makefile`, CI and offline bundle verification | Confirm current-head CI and installed-release acceptance |
| Static and dynamic analysis | Semgrep, CodeQL, security regressions, property fuzzing | Triage confirmed findings; a scan configuration alone is not proof of resolution |
| Cryptography and password storage | Existing authentication, secret handling and TLS boundaries | Maintainer review of legacy compatibility, default algorithms, key sizes and password migration; do not mark compliant from a Scorecard score |
| Secure delivery | HTTPS repository, checksums, signed offline archive provenance | Verify a published archive and its signature against the release workflow identity |
| Secure-development knowledge | Security guidance, reviewed fixes and primary-maintainer confirmation on 2026-10-09 | Maintain knowledge as the application and its threat model evolve |

Review every criterion in the official form. Mark unknown criteria as pending,
and use an explicit justification for any not-applicable answer. Do not invent
response-time history, claim unverified cryptographic compliance, or substitute
AI review for independent maintainer review. Keep the saved assessment
synchronized with verified evidence. Response-history, coverage, cryptographic
compatibility and unresolved analysis criteria require their own evidence;
the five maintainer confirmations do not answer those separate criteria.
