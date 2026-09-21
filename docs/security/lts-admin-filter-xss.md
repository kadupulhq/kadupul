# LTS admin search-filter output batch

This follow-up to PR #149 replaces `html_escape_request_var('filter')` at
13 quoted search-input attributes with explicit native `htmlspecialchars`,
using `ENT_QUOTES | ENT_SUBSTITUTE`. Seven sites are in `user_admin.php` and
six in `user_group_admin.php`.

The existing helper already performs HTML escaping, but was reported as a
taint-propagating call by Sonar in the preceding batch. This change makes the
output contract explicit and preserves entity-like search strings as literal
text by encoding ampersands. It is not a claim of 13 reproduced exploits.
No search/query processing, authorization, CSRF behavior, runtime requirement,
or shared HTML-producing helper is changed.

Each filter has a distinct DOM ID and matching label. Its local JavaScript
selector is updated while the request parameter stays `filter`. The shared
layout also recognizes the `adminFilter` class, preserving initial focus,
mobile sizing and empty-field Backspace behavior after the ID changes.

The regression suite evaluates the production output snippets and parses the
resulting HTML. It checks one input, exactly the original five attributes,
unchanged text values, and no injected script/image nodes. Seven payloads cover
normal and empty searches, Unicode, quotes, element boundaries, entity-like
text, backticks and delimiters: 91 new site/payload combinations. Together with
the previous 392 combinations, the suite exercises 483 combinations in 30 cases.
This is output-boundary coverage, not full browser/controller integration.

PR #149 has the verified post-merge checkpoint below. This follow-up batch still
requires its own post-merge scan; do not dismiss findings or infer closure from
green PR checks.

Verified PR #149 checkpoint: analysis of merged revision
`d03263c43eea0e32c7c11cdf530d8ac2c305c259` at 2026-09-21T03:54:03Z reports
442 unresolved vulnerabilities, down from 491 before that batch.
