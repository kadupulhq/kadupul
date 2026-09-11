# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](VERSIONING.md).

## [Unreleased]

Targeting `v1.3.0`, continuing from the Cacti 1.2.31 fork point. See
[VERSIONING.md](VERSIONING.md).

### Fixed

- The unit test suite can run. Cacti 1.2.31 ships two tests requiring a helper
  the tag does not contain, so the suite had never executed; the helper and
  `lib/type_secure.php` are restored from upstream's own fix.
- Hand-off tests are collected. Two directories differing only in case were
  both tracked and neither was registered, so eight tests never ran. The three
  that need a live database are quarantined rather than reporting success
  while executing nothing.
- Automation listings build a valid `LIMIT`. Both operands came from the
  request unclamped, so a page or row count that validated to zero or below
  produced `LIMIT -30,30` or `LIMIT 0,-5`, which MySQL rejects.
- `CactiSecureType::toString()` guards arrays and objects without
  `__toString`, the conversion its docblock promises to prevent.

### Added

- Repository scaffolding: continuous integration for PHP 8.1 through 8.4 and
  for JavaScript, Dependabot, issue and pull request templates, and the
  security policy.
- Versioning policy and this changelog.
- Semgrep scanning as a blocking gate, and CodeQL for JavaScript and workflows.
- Releases publish an offline deployment tarball with runtime dependencies
  vendored in, plus a checksum, and a multi-architecture container image.
- Release artefacts carry build provenance, the image is signed with cosign,
  and buildx writes an SBOM into the published index.
- The fork import plan, and the decision that the rename stops at the plugin
  API boundary.
- A production container image: multi-stage, base images pinned by digest, the
  application baked in, and every process running unprivileged.
- The migration design, and `tools/migrate/assess.php`, which reports what a
  Cacti install holds and what would survive the move. Read only, so it is safe
  against production.

[Unreleased]: https://github.com/kadupulhq/kadupul/commits/main
