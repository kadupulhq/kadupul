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

Both commands reject redirects, fetch upstream bytes and reject checksum mismatches. All downloads
are checked before `--write` changes any files. Transformations are LF
line-ending normalization and the jQuery UI compatibility patches explicitly
recorded in the manifest: retain `$.uiBackCompat = true`, tolerate malformed
percent escapes in tab fragments, use native anchor parsing with protocol/host
comparison when identifying local tabs, and use jQuery's selector escaping
instead of requiring native `URL`/`CSS.escape` support at the seven widget call sites.
The older-jQuery escape fallback itself keeps its original native implementation.
DOMPurify also caches prototype selector and normalization methods for template recursion so a
form-associated named input cannot clobber its `querySelectorAll` or `normalize` call during
in-place template scrubbing. These fixes have regression tests that failed before
the corresponding patches. License headers and the original upstream DOMPurify
source map remain bundled; the locally patched bundle does not reference that
map, whose line mappings describe unmodified upstream code.
Removed-subtree cleanup also gives `FORBID_ATTR` precedence over `ALLOWED_ATTR`,
including attributes added with `ADD_ATTR`. The active call's `inPlace` mode is
passed explicitly through element, template and attached-shadow traversal so a
reentrant hook's nested string sanitization cannot disable detached-node cleanup.
Seven browser regressions failed before these patches and pass afterward; they
cover forbidden-handler removal and both before/upon element hooks in all three
tree types. These are targeted fixes, not a guarantee of arbitrary reentrant
configuration isolation. Preserve every manifest patch on future refreshes.
Fail-closed cleanup traverses attached shadow roots and template content using
cached prototype getters. An aborted shadow prepass also neutralizes its already
removed subtrees. Two browser regressions reproduce retained event handlers before
these patches and verify their removal afterward.
The final template-expression scrub uses an explicit work stack rather than
recursing through nested template fragments. An isolated test of that production
helper covers 12,000 nested fragments and preserves text normalization/scrubbing.
The scrubber also tolerates missing template constructors and selector results;
when the constructor is absent but content fragments exist, it still scrubs them.
The sanitizer also restores the outer removal ledger after nested calls (including
throws) and resets an omitted per-call Trusted Types policy to the internal
default; persistent `setConfig()` policies and explicit opt-out remain supported.
Sanitize entry rejects reentry from a supplied Trusted Types policy before any
configuration changes, including nested calls that opt out of Trusted Types.
D3's `quantileIndex` materializes non-indexed iterables before accessing indexes,
so sets and single-use generators behave like arrays. Browser tests cover each
correction. All transformations are recorded in the source manifest.
The theme CI job checks provenance and runs real-browser tests
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
