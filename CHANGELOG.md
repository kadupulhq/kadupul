# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](VERSIONING.md).

## [Unreleased]

Targeting `v1.3.0`, continuing from the Cacti 1.2.31 fork point. See
[VERSIONING.md](VERSIONING.md).

### Added

- Update vendored phpseclib to 3.0.57 and constant_time_encoding to 3.1.3, and lock runtime dependencies.

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
