# Kadupul

Network monitoring and graphing, forked from [Cacti](https://github.com/Cacti/cacti).
Kadupul polls devices over SNMP and scripts, stores measurements in RRD files, and
renders graphs with RRDtool.

[Documentation](https://kadupul.org/) · [Issue tracker](https://github.com/kadupulhq/kadupul/issues) · [Discussions](https://github.com/kadupulhq/kadupul/discussions)

## Status

Kadupul is in pre-alpha. The source is available, but there is no supported release
or upgrade path from an existing Cacti installation. It is not ready for production.
See [project status](https://kadupul.org/project/status/) for details.

## First-release goals

The first release aims to provide a tested foundation for running Kadupul and
migrating from Cacti:

- Verify device polling, data collection, and graphing, with automated tests
  covering core behavior and compatibility.
- Preserve Cacti plugin APIs, hooks, templates, and existing RRD history, and
  document any compatibility limits.
- Update the user-facing product name to Kadupul while preserving the interfaces
  existing plugins and integrations depend on.
- Provide a documented installation path and a reversible migration tool for
  Cacti databases, configuration, RRD files, and installed plugins.

These are release goals, not completed features. See the
[fork plan](docs/fork-import.md) for implementation details.

## Documentation

- [Getting started](https://kadupul.org/start/what-kadupul-is/)
- [Installation](https://kadupul.org/start/install/) — planned setup and requirements;
  these instructions describe the intended release.
- [Documentation map](https://kadupul.org/map/) — tutorials, guides, concepts, and reference.
- [Cacti compatibility](https://kadupul.org/project/compatibility-with-cacti/)

## About the fork

Kadupul is forked from Cacti by a major Cacti contributor. It focuses on modernizing
subsystems and improving maintainability while aiming to preserve compatibility
with Cacti plugins, templates, scripts, and integrations. See
[why this fork exists](https://kadupul.org/project/why-this-fork/) for the rationale.

This project is not affiliated with or endorsed by The Cacti Group. Upstream
copyright notices and attribution are preserved.

## The name

Kadupul (කඩුපුල්) is the Sinhala name for *Epiphyllum oxypetalum*, a cactus that
flowers at night. The bloom opens after dark and wilts before dawn.

Cacti takes its name from the plant family. Kadupul is one species inside that
family, so the name says where the project came from without claiming to stand
in for the whole of it.

## Contributing

Read the [contribution guide](https://kadupul.org/project/contributing/) before
opening a pull request. Use the [issue tracker](https://github.com/kadupulhq/kadupul/issues)
for bugs and feature requests, and [Discussions](https://github.com/kadupulhq/kadupul/discussions)
for questions.

Report vulnerabilities through the [security policy](https://kadupul.org/project/security/).

## License

[GPL-3.0-or-later](LICENSE). See the
[licensing documentation](https://kadupul.org/project/license/) for details.
