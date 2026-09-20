# LTS JavaScript dependencies

Checked 2026-09-20 against upstream releases. LTS stays on each existing major
line; newer majors and their browser/API/license changes are not adopted here.
PHP requirements are unchanged. Node 22 is a maintenance/test tool, not a new
application runtime dependency.

| Library | LTS version after refresh | Notes |
| --- | --- | --- |
| UAParser | 1.0.41 | Retains the MIT-licensed 1.x line |
| D3 | 7.9.0 | Retains the tested single-use iterable/NaN quantile-index fixes |
| tablesorter core/widgets/pager | 2.32.0 | Updated together |
| Sparkline | 2.4.0 | Same jQuery plugin API |
| hotkeys-js | 3.13.15 | Midwinter vendor copy |
| jQuery Hotkeys | 0.2.2 | Separate plugin, not hotkeys-js |
| Vanderlee Colorpicker | 1.2.21 | The vanderlee-colorpicker package, not a namesake |
| Font Awesome Free | 5.15.4 | JS, CSS, fonts, SVGs, sprites, metadata and style sources together |
| jQuery Cookie | 1.4.1 + LTS adapter | Preserves missing-cookie null, per-call raw options, null/undefined deletion and calendar-day expiration |
| Dygraphs | 1.1.1 | Existing code matches; adds its matching source map |
| TableDnD | 0.9.2 | Existing source already matches the latest 0.x tag |
| jQuery UI Multiselect | 3.0.1 | Includes filter and all bundled translations; upstream source still says 3.0.0 |
| jQuery | 3.7.1 | Already latest 3.x; unchanged |
| jQuery UI | 1.14.2 | Already latest 1.x; local LTS modifications preserved |
| DOMPurify | 3.4.15 | Already latest 3.x; local LTS modifications preserved |
| Chart.js | 2.9.4 | Already latest 2.x; unchanged |
| billboard.js | 3.18.0 | Already latest 3.x; unchanged |
| screenfull | 3.3.3 | Already latest 3.x; unchanged |
| jsTree | 3.3.17 | Already latest 3.x; unchanged |
| Pace | 1.2.4 | Already latest 1.x; unchanged |
| js-storage | 1.1.0 | Already latest 1.x; unchanged |
| Timepicker Addon | 1.6.3 | Already latest 1.x; unchanged |
| Touch Punch | 0.2.3 | Already latest 0.x; unchanged |

## Exceptions and scope

- Midwinter's mark.js identifies itself as 9.0.0, but upstream's latest tagged
  release is 8.11.1. Retain the existing snapshot rather than downgrade it or
  assert that an unpublished version has a newer minor release.
- jquery.dropdown.js and jquery.zoom.js are application code, not npm packages.
- JavaScript inside the PHP CSRF/HTMLPurifier vendor directories is maintained
  with its parent PHP library; it has no independent JavaScript release line.
- jQuery Cookie is unmaintained. CVE-2022-23395 remains listed by GitHub under
  NuGet; upstream discusses ambiguous applicability in js-cookie/js-cookie#766.
  Do not equate the 1.4.1 upgrade with closing this advisory. Tests cover the
  actual bundled jQuery 3.7.1 options merge; the all-cookie map uses a null
  prototype. Migration to a different library is outside a same-major refresh.
- The LTS cookie adapter deliberately retains the old function-valued write
  behavior. The newer upstream read-converter overload is not enabled: adopting
  it would turn an existing write into a read/callback execution.

## Reproduction

Install only into a temporary directory, with lifecycle scripts disabled:

```sh
vendor_dir=$(mktemp -d)
mise exec node@22.22.2 -- npm install --prefix "$vendor_dir" --ignore-scripts --no-audit --no-fund --package-lock=false ua-parser-js@1.0.41 d3@7.9.0 tablesorter@2.32.0 jquery-sparkline@2.4.0 hotkeys-js@3.13.15 jquery-hotkeys@0.2.2 @fortawesome/fontawesome-free@5.15.4 vanderlee-colorpicker@1.2.21 dygraphs@1.1.1 jquery.cookie@1.4.1 jquery-ui-multiselect-widget@3.0.1
mise exec node@22.22.2 -- node tools/dependencies/sync.mjs --check --source="$vendor_dir/node_modules"
```

Use `--write` to regenerate. The manifest pins upstream SHA-256 hashes before
applying exact-context compatibility patches. Font Awesome's complete selected
tree is pinned by a sorted path/content hash and file count. The sync command
does not remove existing application files. The tablesorter pager and TableDnD
come from pinned upstream Git tags/commits because the required artifacts are
not in their npm package releases. Git-normalized LF output is deterministic.

Run `tests/e2e/vendor-lts.spec.js` through the existing Playwright configuration.
The dedicated LTS CI job serves these browser tests with PHP 8.0. These tests
do not need an application database or administrator credentials.
