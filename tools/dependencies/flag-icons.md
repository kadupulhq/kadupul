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

Local non-runtime patches align the README CDN example with version 7.5.0 and
change both Python maintenance scripts to require the exact `.svg` suffix and
remove only that final extension. This prevents backup/temporary files such as
`xx.svg.bak` from being modified or reported as country codes. Preserve these
patches when refreshing the upstream archive. Kadupul serves local CSS and does
not invoke these maintenance scripts in the application.

`mise exec python@3.12.12 -- python tests/Support/flag_icons_helpers_test.py`
executes the real scripts against disposable fixtures, never the bundled asset
directories. CI runs these tests using its existing Python toolchain. Two tests
failed before the patches and pass afterward; a third verifies that unknown real
SVG files still fail the country-code check. Application runtime support is unchanged.
