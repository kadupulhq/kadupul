# Kadupul

Network monitoring and graphing. Kadupul polls devices over SNMP and scripts,
stores measurements in RRD files, and renders graphs with RRDtool.
It is an independent fork of [Cacti](https://github.com/Cacti/cacti), without affiliation or endorsement from that project.

[Documentation](https://kadupul.org/) · [Issue tracker](https://github.com/kadupulhq/kadupul/issues) · [Discussions](https://github.com/kadupulhq/kadupul/discussions)

## Status

Kadupul is in pre-alpha. The source is available, but there is no supported release
or migration path. It is not ready for production.

## First-release goals

- Verify device polling, data collection, and graphing with automated tests.
- Preserve plugin APIs, hooks, templates, and existing RRD data.
- Improve security boundaries and maintainability.
- Provide documented installation and reversible migration procedures.

These are goals, not completed features. See [project status](https://kadupul.org/project/status/).

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

### Spike-removal platform requirement

Spike removal currently requires a POSIX system with the PHP POSIX extension.
Its filesystem safety checks refuse Windows and other systems without POSIX
account identity. Windows ACL validation is not implemented, so spike removal
is unavailable there; this restriction does not disable other application features.
See [filesystem requirements](docs/testing/spikekill-safety.md).
