# OpenSSF Best Practices enrollment evidence

This is an evidence inventory for a maintainer completing the
[passing-badge assessment](https://www.bestpractices.dev/en/criteria/0).
It is not a completed assessment or a badge claim. Register the canonical
repository `https://github.com/kadupulhq/kadupul` through the
[authenticated enrollment form](https://www.bestpractices.dev/en/projects/new).

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
| Secure-development knowledge | Security guidance and reviewed fixes | A primary developer must supply the personal knowledge attestations |

Review every criterion in the official form. Mark unknown criteria as pending,
and use an explicit justification for any not-applicable answer. Do not invent
response-time history, claim unverified cryptographic compliance, or substitute
AI review for independent maintainer review. Once enrollment exists, record its
project ID and public assessment URL in `openssf.md`.
