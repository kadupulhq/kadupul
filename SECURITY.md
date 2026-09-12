# Security policy

## Reporting a vulnerability

Report privately through
[GitHub Security Advisories](https://github.com/kadupulhq/kadupul/security/advisories/new).
Do not open a public issue or publish a fix before private triage.

Include the version or commit, reproduction steps, required configuration, and
whether the issue is reachable before authentication.

## Coordinated disclosure

Maintainers aim to acknowledge reports within three working days and assess exploitability and exposure privately.
For shared-code vulnerabilities, maintainers must contact affected projects through
their private security channels before publishing an advisory or fix, and agree
on a coordinated disclosure timeline. Reporters should include any related private
reports so maintainers can coordinate without exposing the finding publicly.
Unreleased code may still be deployed; report privately regardless of release status.

## Supported versions

No supported release is available yet. Reports against the source are welcome.

## Scope

In scope: the application, the poller, the installer, and the packaging in this
repository.

Out of scope: third-party plugins, RRDtool, Net-SNMP, the web server, the
database, and anything that requires an administrator to act against their own
install.
