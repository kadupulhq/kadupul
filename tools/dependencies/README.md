# Third-party dependency maintenance

The application still declares PHP >=8.0; its CI matrix remains PHP 8.1–8.4.
Root Composer resolution is pinned to PHP 8.0.0 and test dependency resolution
to PHP 8.1.0, so updating on a newer developer machine cannot silently raise
either floor. Node is maintenance/test tooling, not an application requirement.

## Composer

Run updates through `mise` using the supported runtime. Commit both the lockfile
and the tracked `include/vendor` changes: a current lockfile alone does not mean
the bundled code is current. Do not overwrite the unmanaged libraries sharing
that directory. Run `composer audit` for both the root and `tests` projects.

## Browser assets

`assets.json` records the exact upstream URLs, package versions and SHA-256
checksums for the libraries refreshed in September 2026. This is a partial
inventory, not a claim that every bundled library is current.

```sh
mise exec node@22 -- node tools/dependencies/sync.mjs --check
mise exec node@22 -- node tools/dependencies/sync.mjs --write
```

Both commands fetch upstream bytes and reject checksum mismatches. All downloads
are checked before `--write` changes any files. Transformations are LF
line-ending normalization and the jQuery UI compatibility patches explicitly
recorded in the manifest: retain `$.uiBackCompat = true`, tolerate malformed
percent escapes in tab fragments, use native anchor parsing with protocol/host
comparison when identifying local tabs, and use jQuery's selector escaping
instead of requiring native `URL`/`CSS.escape` support. License headers and the DOMPurify source map
remain bundled. The theme CI job checks provenance and runs real-browser tests
for sanitization, legacy widgets, sorting/paging and D3 rendering.

Before changing a pin, compare the current file with its old upstream release
and retain any application/security patches. Obtain the new checksum from the
reviewed release, then run the sync and browser suite. Do not substitute a
similarly named npm package: Kadupul's `jquery.zoom.js` is application-owned,
and `jquery-ui-dist` currently lags the official jQuery UI distribution.

## Remaining upgrade work (2026-09-19)

This first compatibility-preserving batch updates DOMPurify 3.4.7 → 3.4.15,
D3 7.8.2 → 7.9.0, jQuery UI 1.14.0 → 1.14.2, and tablesorter core/widgets/pager
to 2.32.0. It synchronizes the bundled HTML Purifier 4.19.0 with the already
locked 4.19.1 and updates compatible test dependency patches.

- phpseclib remains on 3.0.57. Version 4 requires PHP 8.1 and a namespace/API
  migration, so it cannot replace version 3 while retaining the PHP 8.0 floor.
- Pest remains on 1.23.1 for this batch. Pest 2 can run on PHP 8.1, but migrating
  to PHPUnit 10 requires updating the native subprocess coverage integration.
  This is migration work, not a runtime incompatibility excuse; newer Pest
  majors also raise the PHP minimum.
- PHPMailer, gettext, legacy graph/widget libraries and icon assets still need
  separate upstream/compatibility review. They are not covered by root Composer
  or the E2E npm audit merely because their files live in this repository.
- jQuery 4, Chart.js 4, billboard.js 4, dygraphs 2, screenfull 6 and Font Awesome
  7 must not be treated as drop-in replacements. Their API, module or browser
  compatibility changes need call-site migration and dedicated regression tests.
- The multiselect implementation already matches upstream 3.0.1 despite its
  stale 3.0.0 banner; its filter differs only in whitespace. jstree 3.3.17,
  js-storage 1.1.0, pace-js 1.2.4 and Playwright 1.63.0 match the latest releases
  checked for this batch. An archived package having no newer release is not a
  security endorsement.

Upstream references: [phpseclib migration](https://phpseclib.com/docs/intro/migrating),
[Pest upgrade guide](https://pestphp.com/docs/upgrade-guide),
[jQuery 4 migration](https://jquery.com/upgrade-guide/4.0/),
[DOMPurify releases](https://github.com/cure53/DOMPurify/releases).
