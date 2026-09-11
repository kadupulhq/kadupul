# Security policy

## Reporting a vulnerability

Report privately through
[GitHub Security Advisories](https://github.com/kadupulhq/kadupul/security/advisories/new).
Do not open a public issue, and do not send a pull request that fixes an
unreported vulnerability, because the diff discloses it.

Include the version or commit, the configuration needed to reach the code, and
whether the issue is reachable before authentication. A proof of concept helps
and does not need to be weaponised.

You should get an acknowledgement within three working days.

## Vulnerabilities inherited from Cacti

Kadupul is a fork of [Cacti](https://github.com/Cacti/cacti) and shares most of
its code. If a vulnerability also affects stock Cacti, report it to
[Cacti's security process](https://github.com/Cacti/cacti/security/policy)
first. Coordinated disclosure protects every Cacti install, not just this fork,
and a fix that lands only here leaves the larger population exposed.

Tell us in your report that you have done so, and we will track their timeline
rather than publishing ahead of it.

## Supported versions

Nothing is released yet, so nothing is supported. This section will list the
maintained branches once there is a first release.

## Scope

In scope: the application, the poller, the installer, and the packaging in this
repository.

Out of scope: third-party plugins, RRDtool, Net-SNMP, the web server, the
database, and anything that requires an administrator to act against their own
install.
