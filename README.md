<p align="center">
  <img src="assets/primary.png" alt="" width="170">
</p>

<h1 align="center">Kadupul</h1>

<p align="center">
  <strong>Network monitoring and graphing. A fork of Cacti.</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/license-GPL--3.0--or--later-004C38" alt="License GPL-3.0-or-later">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4" alt="PHP 8.1 or newer">
  <img src="https://img.shields.io/badge/status-pre--alpha-FBBB02" alt="Status pre-alpha">
</p>

---

> **Nothing here works yet.** This repository holds the name, the mark, and this file.
> The fork has not been taken. There is no code, no build, no release, and no install path.

## About

Kadupul is a fork of [Cacti](https://github.com/Cacti/cacti), the PHP network monitoring
and graphing tool. It keeps what Cacti does: poll devices over SNMP and scripts, store the
results in RRD files, and draw graphs from them.

## Relationship to Cacti

Kadupul stays API compatible with Cacti for the foreseeable future. Plugins, templates,
scripts, and integrations written against Cacti are meant to keep working. Where the two
diverge, it will be documented rather than silent.

Copyright notices and attribution to The Cacti Group stay in place in every file carried
over from upstream.

| | |
|---|---|
| Upstream | [Cacti/cacti](https://github.com/Cacti/cacti) |
| License | GPL-3.0-or-later. See below |
| API compatibility | Maintained with Cacti for the foreseeable future |
| Current upstream release | 1.2.32 |
| Upstream development line | 1.3.0, in `develop` |
| Fork point | not chosen yet |

## Origin

Kadupul is forked from Cacti by a long-time Cacti contributor. As of September 2026: 347
commits on `develop`, ranking 9th of 195 authors, 116 commits on the 1.2.x line, and 523
merged pull requests. Member of the Cacti security and reviewer teams, and credited in the
upstream README for code, security, maintenance, infrastructure, tests, and documentation.

This fork is not affiliated with or endorsed by The Cacti Group.

## Requirements

Inherited from Cacti's manifest. These move once the fork point is set.

| | |
|---|---|
| PHP | 8.1 or newer, with the extensions Cacti's `composer.json` requires |
| Database | MySQL or MariaDB |
| Graphing | RRDtool |
| Collection | net-snmp |

## The name

Kadupul is a night-blooming flower. It opens after dark and closes before morning. The
mark places a four-point metric trace at the center of the bloom.

## Brand

| File | Use |
|---|---|
| [`assets/primary.png`](assets/primary.png) | Full color on a transparent background |
| [`assets/one-color.png`](assets/one-color.png) | Forest green on white, single ink |
| [`assets/reversed-white.png`](assets/reversed-white.png) | White on forest green, for dark backgrounds |
| [`assets/favicon-master.png`](assets/favicon-master.png) | Simplified mark with the stamens removed |
| [`assets/favicon-32.png`](assets/favicon-32.png), [`assets/favicon-16.png`](assets/favicon-16.png) | Browser tab |

| Color | Hex | |
|---|---|---|
| Forest green | `#004C38` | ![](https://img.shields.io/badge/-004C38-004C38) |
| Gold | `#FBBB02` | ![](https://img.shields.io/badge/-FBBB02-FBBB02) |
| Ivory | `#FDFAF1` | ![](https://img.shields.io/badge/-FDFAF1-FDFAF1) |

The PNGs were generated rather than drawn, so their greens sample between `#024930` and
`#01553C` instead of the specified `#004C38`. Redraw the mark as vector before using it
anywhere the exact color matters. [`assets/GENERATION-NOTES.txt`](assets/GENERATION-NOTES.txt)
records how each variant was produced.

## License

[GPL-3.0-or-later](LICENSE).

Cacti's source headers grant the program "either version 2 of the License, or (at your
option) any later version". That is GPL-2.0-or-later, and it is the upstream grant that
governs. 420 of 442 core PHP files carry it verbatim, and no file in the tree is version 2
only. Kadupul takes the later-version option and distributes under version 3.

Cacti's `composer.json` declares `GPL-2.0-only`, which contradicts its own file headers.
It also conflicts with a dependency Cacti already ships, `greew/oauth2-azure-provider`,
which is GPL-3.0-or-later and cannot be combined with version 2 only. Moving to version 3
resolves that conflict rather than creating one.

Upstream copyright headers stay as they are. Version 3 applies to the work as distributed
here.
