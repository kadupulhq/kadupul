# Explicit row choices for legacy permission filters

The shared permission filter renderer now receives its row-choice catalog as an
argument. Its `global $item_rows` declaration is removed. The existing bootstrap
catalog is passed from the user/group edit dispatchers through the permission
and membership views to all eleven filter wrappers. No replacement catalog or
new global read is introduced.

This is an architectural improvement on `main`; LTS is unchanged. Existing
Symfony/Twig permission views keep their current routes. This change concerns
the retained legacy edit/filter path and does not retire that path or its writer.

## Catalog and plugin compatibility

`include/global_arrays.php` remains the source of the shared catalog. Its
`max_input_vars` limit, option ordering, labels and customization remain intact.
The `-1` default option stays in the renderer and is not folded into the catalog.
An explicitly empty catalog renders only that default option. Supplied labels
and keys keep the existing HTML escaping and selected-value rules.

The top-level `user_edit()` and `group_edit()` entry functions accept the
bootstrap array by reference because tab hooks run before the filter is rendered.
This preserves a hook's ability to change the catalog during the same request.
The permission/membership views and filter renderer then receive array values;
they do not access the global variable. The reference is a legacy compatibility
boundary, not a new application/domain dependency.

Authentication, authorization, request/filter restoration, CSRF, form targets,
plugin names/order, SQL and writes retain their existing owners. No schema,
runtime, dependency, route or JavaScript change is required. The required PHP
arguments change together: deploy the helper and both pages as one unit and
roll them back together. Repository callers and native fixtures are updated.
No fallback reads global state for an old internal call signature.

## Verification and limits

Existing native tests execute the page filters, permission grids and edit-page
entry functions. Added cases supply a catalog that disagrees with ambient
`$item_rows`, including an empty catalog, selected values, option order and
HTML-like labels. Another regression changes the catalog inside the real tab
hook and checks the final user/group dropdown and unchanged stored permissions.
The architecture guard rejects direct globals and superglobals in the renderer.
Subprocess coverage registrations include the renderer and require executed
source for grid scenarios; coverage-driver execution is reported separately.

The declaration ratchet falls from 902 declarations/1701 bindings to 901/1700
for the base snapshot `c68a8c0b0`. Within `src/`, it falls from ten declarations
with eighteen bindings to nine/seventeen. These counts do not include other
pending cleanup PRs.

The renderer still calls legacy request, translation, nonce, HTML and database
helpers. The bootstrap catalog remains shared by other legacy pages. This slice
removes one direct global dependency; it does not claim the full renderer is a
pure Twig view or that all legacy state has been removed. Installed session,
collector and full browser acceptance remain separate checks. Local evidence
and the exact tested revision are recorded on the PR.
