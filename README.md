# Kadupul

Network monitoring and graphing. Kadupul polls devices over SNMP and scripts,
stores measurements in RRD files, and renders graphs with RRDtool.
It is an independent fork of [Cacti](https://github.com/Cacti/cacti), without affiliation or endorsement from that project.

[Documentation](https://kadupul.org/) · [Issue tracker](https://github.com/kadupulhq/kadupul/issues) · [Discussions](https://github.com/kadupulhq/kadupul/discussions)

## Status

Kadupul is in pre-alpha. The source is available, but there is no supported release
or migration path. It is not ready for production.

## First-release goals

Main requires PHP 8.4 or later and uses phpseclib 4.x. The `lts/1.2` branch
retains PHP 8.0 support and phpseclib 3.x.

- Verify device polling, data collection, and graphing with automated tests.
- Preserve plugin APIs, hooks, templates, and existing RRD data.
- Improve security boundaries and maintainability.
- Provide documented installation and reversible migration procedures.

These are goals, not completed features. See [project status](https://kadupul.org/project/status/).

## Development and offline installation

Main is beginning an incremental Symfony 7.4 migration. Source checkouts install
PHP dependencies with Composer and browser dependencies with npm. Generated
vendor directories are not tracked. Dependency-complete release archives support
offline installation without Composer or Node on the target host.
See [migration and installation instructions](docs/symfony-migration.md).

## Documentation

- [Getting started](https://kadupul.org/start/what-kadupul-is/)
- [Installation](https://kadupul.org/start/install/)
- [Documentation map](https://kadupul.org/map/)
- [Compatibility](https://kadupul.org/project/compatibility/)

## The name

Kadupul (කඩුපුල්) is the Sinhala name for *Epiphyllum oxypetalum*, a cactus that
flowers at night. The bloom opens after dark and wilts before dawn.

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.
Report vulnerabilities through [SECURITY.md](SECURITY.md).

## License

[GPL-3.0-or-later](LICENSE). Dependencies retain their own license terms.
See the [licensing documentation](https://kadupul.org/project/license/).

### RRD storage platform requirements

Local Unix RRD storage requires PHP's POSIX extension for account identity and
filesystem trust checks. This applies to ordinary poller writes as well as
exclusive maintenance. Without it, local writers fail closed and retain queued
samples; enable the extension for both CLI and web PHP before running collection.

Spike removal and exclusive RRD maintenance are unavailable on Windows because
Windows ACL validation is not implemented. Ordinary Windows updates use
synchronous RRDtool acknowledgements; Unix capacity evidence does not establish
Windows throughput. Remote RRDtool proxy storage retains its existing path.
See [filesystem requirements](docs/testing/spikekill-safety.md).
