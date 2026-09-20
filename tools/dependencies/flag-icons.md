# Flag-icons provenance

Updated 6.11.1 → 7.5.0 on 2026-09-19. This CSS/SVG asset update does not change
application PHP or browser runtime minimums. It adds no application dependencies
and requires no CSS class/path migrations.

- Upstream release: https://github.com/lipis/flag-icons/releases/tag/v7.5.0
- Archive: https://codeload.github.com/lipis/flag-icons/zip/refs/tags/v7.5.0
- Archive SHA-256: `7eafedfc07e16ce640dd53309fbe147be0dcfd36d027646cf3798aea79a18913`

All existing bundled files matched upstream 6.11.1 before the update. Runtime
CSS, flags, Sass sources and existing upstream metadata/documentation were copied
from the verified archive, retaining the MIT license and including new flags.
No local runtime patches are applied.

Upstream 7 removed Less build inputs; the five unused bundled Less files were
removed as part of this update and remain recoverable from Git. Kadupul does not
build or import those files: it serves the precompiled CSS. Upstream now uses
Sass modules to build its assets. This changes optional upstream asset-building
inputs, not Kadupul's supported application runtimes.

Compatibility tests check every configured locale country code, both aspect
ratios, every asset URL in minified/unminified CSS, and XML validity/passive
content/internal references for all bundled SVGs. Upstream's demo/build tooling
is not installed or used by the application and is not part of root Composer or
the E2E npm dependency audit.

Upstream documentation still illustrates a 7.3.2 CDN URL; Kadupul does not use
that URL and serves the bundled 7.5.0 CSS locally. The upstream Python maintenance
scripts use substring-based SVG filename filters and should not be run on
directories containing backup files such as `xx.svg.bak`. They are retained
unmodified for provenance, are not invoked by Kadupul or CI, and are not the
validation mechanism for shipped assets. Use the compatibility tests above.
