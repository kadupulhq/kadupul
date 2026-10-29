# Third-party dependency maintenance

Main requires PHP >=8.4. Composer resolves against PHP 8.4.0, and `mise.toml`
pins the local development tools. LTS retains its existing distribution model.

## Composer and npm installs

Commit manifests, lockfiles and reviewed compatibility patch recipes, not generated
`include/vendor`, `include/fa` or npm-built browser bundles. Run `composer install`,
`npm ci --ignore-scripts` and `npm run build` through `mise`. The legacy source
snapshot is installed by Composer's post-install/update script and preserves local
security fixes until those features migrate. It is not covered by Composer audit.
A `patches` entry in `legacy-files.json` applies exact replacements to one snapshot
file: the installer checks the archive bytes against `source_sha256`, requires each
`before` string to occur once, and checks the result against the digest in `files`.
CSRF Magic's browser script and output handler use this to add the token only to
same-origin requests and forms, as the 1.2 LTS copy does.

See [Symfony migration and offline bundles](../../docs/symfony-migration.md) for
source installation, compatibility exceptions and disconnected deployment.

## Browser assets

`assets.json` inventories every file directly under `include/js`. Each entry
sets exactly one of `url`, `tracked` or `owned`, and `owned`, `tracked` and
`npm` must be JSON booleans; `sync.mjs` rejects anything else. Entries with a
`url` are fetched from upstream by `--check` and `--write`, as below. Entries
marked `tracked` are committed as shipped: `--check` compares the file against
its recorded SHA-256. `--write` does not verify tracked bytes, so run `--check`
after editing one. A `source` URL names the upstream release those bytes came
from; a `modified` note lists any difference from it, and an entry with neither
has no matching upstream release. `owned` entries are maintained in this
repository and have no upstream, so they carry no checksum. They include
`jquery.zoom.js` and `jquery.dropdown.js`, which are inherited Cacti code that
keeps its Cacti Group GPL-2.0 headers, and the files written for this project.
`--check` fails when a file directly under `include/js` has no entry, and when
an entry names a file that no longer exists. The `LC_MESSAGES` datepicker
locale files are not inventoried yet.

An entry with `frozen` stays at its pinned version for the 1.3 line and the
reason is recorded beside it. Inventory presence does not mean a library is
current. `node tools/dependencies/sync.mjs --outdated` compares each pinned npm
package, from `package.json` and the inventory, with `npm view <package>
version`. It exits nonzero when a package that is not frozen is behind, and
lists frozen packages without failing on them. The scheduled
`vendor-drift.yml` workflow runs it weekly as an informational step.

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
Template content reads share one helper that returns no content for an element
other than `HTMLTemplateElement`, matching upstream's plain property read. Aborted
cleanup detaches each child of the root, shadow roots and template content with the
cached `Node.prototype.removeChild`, working from a snapshot of the child list and
continuing past a failed removal. Eight browser regressions failed before these
patches and pass afterward.
The final template-expression scrub uses an explicit work stack rather than
recursing through nested template fragments. An isolated test of that production
helper covers 12,000 nested fragments and preserves text normalization/scrubbing.
The scrubber also tolerates missing template constructors and selector results;
when the constructor is absent but content fragments exist, it still scrubs them.
Discarded-subtree cleanup uses the same missing-constructor fallback. Detachment
checks also cover both after-element hook paths and all three attribute hook
phases, carrying the active root and in-place mode through light, template, and
shadow traversal. Sixteen additional browser cases reproduced retained event
handlers before these patches and verify their removal afterward.
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

The build installs only Font Awesome's `css/all.css`, `webfonts/*.woff2` and
`LICENSE.txt` into a cleared `include/fa`, with `index.php` directory guards.
Font URLs in `all.css` carry `?v=<package version>`, because Font Awesome 5
and 7 use the same font file names and browsers cache fonts by URL.

Before changing a pin, compare the current file with its old upstream release
and retain any application/security patches. Obtain the new checksum from the
reviewed release, then run the sync and browser suite. Do not substitute a
similarly named npm package: Kadupul's `jquery.zoom.js` is application-owned,
and `jquery-ui-dist` currently lags the official jQuery UI distribution.

## Remaining upgrade work (2026-09-19)

DOMPurify is pinned to 3.4.17 and Playwright to 1.64.0. The manifest verifies
the original source and map checksums before applying the local compatibility
recipes. The DOMPurify refresh preserves the existing protections and incorporates
upstream tracking of original nodes moved during in-place sanitization, the XHTML
SMIL attribute-value guard, and the per-fragment template-expression scrub.
The new traversal passes carry the local in-place flag, cached normalization,
and abort cleanup rules. Browser regressions cover moved and escaped nodes,
force-kept SMIL attributes, and merged expressions in shadow and template content.
Discarded subtrees are cleaned once through their original ancestor rather than
walking the same descendants for every original node.

This first compatibility-preserving batch updates DOMPurify 3.4.7 → 3.4.15,
D3 7.8.2 → 7.9.0, jQuery UI 1.14.0 → 1.14.2, and tablesorter core/widgets/pager
to 2.32.0. It synchronizes the bundled HTML Purifier 4.19.0 with the already
locked 4.19.1 and updates compatible test dependency patches.

- Main uses phpseclib 4 with PHP >=8.4; LTS retains phpseclib 3 and PHP >=8.1.
  See [phpseclib.md](phpseclib.md) for migration scope and remaining legacy proxy code.
- Pest remained on 1.23.1 for this batch. #463 later moved `tests/composer.lock`
  to Pest 4.7.8 on PHPUnit 12.5; the root `composer.lock` keeps PHPUnit 10.5 for
  `composer test`.
- PHPMailer, gettext, legacy graph/widget libraries and icon assets still need
  separate upstream/compatibility review. They are not covered by root Composer
  or the E2E npm audit merely because their files live in this repository.
- Chart.js 4, billboard.js 4 and dygraphs 2 must not be treated as
  drop-in replacements. Their API, module or browser compatibility changes need
  call-site migration and dedicated regression tests. screenfull is no longer
  bundled: `include/js/screenfull.js` is an application-owned shim over the native
  Fullscreen API that keeps the screenfull 3.x global API for plugins. jQuery 4.0.0 now ships with
  the call-site fixes from #834, and Font Awesome 7.3.1 is installed from
  `package.json` (#152).
- The multiselect implementation already matches upstream 3.0.1 despite its
  stale 3.0.0 banner; its filter differs only in whitespace. jstree 3.3.17,
  js-storage 1.1.0, pace-js 1.2.4 and Playwright 1.63.0 match the latest releases
  checked for this batch. An archived package having no newer release is not a
  security endorsement.

jQuery Migrate 4.0.2 loads on every page directly after jQuery. jQuery 4 removed
helpers such as `$.isFunction`, `$.trim`, `$.isNumeric` and `$.isWindow`, which
the bundled timepicker, colorpicker, sparkline, zoom, tableDnD and tablesorter
widgets still call, as do plugins written for Cacti 1.2. The manifest adds
`jQuery.migrateMute = true` to the built file, so deprecated calls are recorded in
`jQuery.migrateMessages` without console warnings; set it to `false` in the
browser console to see them. Remove Migrate once the bundled widgets no longer
use the removed APIs and supported plugins have had a release to migrate.

Upstream references: [phpseclib migration](https://phpseclib.com/docs/intro/migrating),
[Pest upgrade guide](https://pestphp.com/docs/upgrade-guide),
[jQuery 4 migration](https://jquery.com/upgrade-guide/4.0/),
[DOMPurify releases](https://github.com/cure53/DOMPurify/releases).

The patched CSRF library also supplies the rotation CLI with atomic secret publication: an exclusive temporary file in the configured destination directory, complete write/readback, preserved and verified existing UID/GID, mode 0640, synchronization and rename. Failed generation or publication retains the working key; symlink file destinations are rejected.
