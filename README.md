# Kadupul

Network monitoring and graphing. Kadupul polls devices over SNMP and scripts,
stores measurements in RRD files, and renders graphs with RRDtool.
It is an independent fork of [Cacti](https://github.com/Cacti/cacti), without affiliation or endorsement from that project.

**Kadupul is in pre-alpha and is not ready for production.** The source is
available, but there is no supported release and no migration path. See
[project status](https://kadupulhq.github.io/website/project/status/).

[Documentation](https://kadupulhq.github.io/website/) · [Issue tracker](https://github.com/kadupulhq/kadupul/issues) · [Discussions](https://github.com/kadupulhq/kadupul/discussions)

## Branches

| Branch | PHP | phpseclib | Notes |
|---|---|---|---|
| `main` | 8.4 or later | 4.x | Incremental Symfony 7.4 migration |
| `lts/1.2` | 8.1 or later | 3.x | Tracks the 1.2 series |

## Running from source

Generated vendor directories are not tracked, so a checkout installs its own
dependencies:

```sh
mise exec -- php "$(command -v composer)" install
mise exec -- npm ci --ignore-scripts
mise exec -- npm run build
mise exec -- php bin/console about
```

Composer generates `include/vendor/`; npm supplies the browser packages.
Release archives ship those dependencies so a target host needs neither
Composer nor Node. See
[migration and installation instructions](docs/symfony-migration.md).

## Platform requirements

Local Unix RRD storage requires PHP's POSIX extension for account identity and
filesystem trust checks. This applies to ordinary poller writes as well as
exclusive maintenance. Without it, local writers fail closed and retain queued
samples; enable the extension for both CLI and web PHP before running collection.

Spike removal and exclusive RRD maintenance are unavailable on Windows because
Windows ACL validation is not implemented. Ordinary Windows updates use
synchronous RRDtool acknowledgements; Unix capacity evidence does not establish
Windows throughput. Remote RRDtool proxy storage retains its existing path.
See [filesystem requirements](docs/testing/spikekill-safety.md).

## First-release goals

These are goals, not completed features.

- Verify device polling, data collection, and graphing with automated tests.
- Preserve plugin APIs, hooks, templates, and existing RRD data.
- Improve security boundaries and maintainability.
- Provide documented installation and reversible migration procedures.

## Documentation

- [Getting started](https://kadupulhq.github.io/website/start/what-kadupul-is/)
- [Installation](https://kadupulhq.github.io/website/start/install/)
- [Documentation map](https://kadupulhq.github.io/website/map/)
- [Compatibility](https://kadupulhq.github.io/website/project/compatibility-with-cacti/)

## The name

Kadupul (කඩුපුල්) is the Sinhala name for *Epiphyllum oxypetalum*, a cactus that
flowers at night. The bloom opens after dark and wilts before dawn.

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.
Report vulnerabilities through [SECURITY.md](SECURITY.md).

## License

[GPL-3.0-or-later](LICENSE). Dependencies retain their own license terms.
See the [licensing documentation](https://kadupulhq.github.io/website/project/license/).
