# LTS admin output-context batch

Baseline: LTS revision `c44248e07fd9feb3ac2d4a52a66c6ab0d46c0f3c`,
491 unresolved vulnerabilities, including 417 XSS findings. The two admin
controllers contain 90 XSS findings; this batch targets the 49 sinks below.

## Scope and compatibility

- Encode request IDs as URL-component data before embedding them in single-quoted
  JavaScript URL strings. Percent encoding protects quote, script-tag and query
  delimiters while preserving the value when the request is parsed.
- HTML-escape IDs and tab values in quoted hidden-input attributes.
- Do not change request validation, authorization, routing, database values,
  CSRF guards, supported PHP versions, or helpers that intentionally emit HTML.
- Existing integer validation may already prevent exploitation of some paths.
  These changes enforce safety at the output boundary; they are not a claim that
  every scanner trace was independently exploitable.
- The regression test evaluates the production output snippets and real HTML
  escaping function, checks JavaScript string boundaries and URL round trips,
  and parses HTML to verify one input, unchanged values and no injected elements
  or attributes. It covers all 49 sites across six payloads (294 site/payload
  combinations). It is output-boundary coverage, not a full browser/admin-flow
  exploit reproduction.
- Do not mark findings closed until a fresh merged-LTS scan confirms resolution.

## Targeted scanner keys

| File | Baseline line | Sonar key |
|---|---:|---|
| user_admin.php | 2630 | AaCs2PLrIug_wyaLilqh |
| user_admin.php | 2749 | AaCs2PLrIug_wyaLilqj |
| user_admin.php | 2847 | AaCs2PLrIug_wyaLilrC |
| user_admin.php | 2962 | AaCs2PLrIug_wyaLilq2 |
| user_admin.php | 3059 | AaCs2PLrIug_wyaLilq6 |
| user_admin.php | 3156 | AaCs2PLrIug_wyaLilqf |
| user_admin.php | 829 | AaCs2PLrIug_wyaLilqa |
| user_admin.php | 1109 | AaCs2PLrIug_wyaLilqi |
| user_admin.php | 1274 | AaCs2PLrIug_wyaLilqx |
| user_admin.php | 1418 | AaCs2PLrIug_wyaLilq0 |
| user_admin.php | 830 | AaCs2PLrIug_wyaLilqs |
| user_admin.php | 1275 | AaCs2PLrIug_wyaLilqw |
| user_admin.php | 2724 | AaCs2PLrIug_wyaLilq_ |
| user_admin.php | 2821 | AaCs2PLrIug_wyaLilqb |
| user_admin.php | 1110 | AaCs2PLrIug_wyaLilq7 |
| user_admin.php | 1419 | AaCs2PLrIug_wyaLilrD |
| user_admin.php | 2620 | AaCs2PLrIug_wyaLilqe |
| user_admin.php | 2740 | AaCs2PLrIug_wyaLilqq |
| user_admin.php | 2837 | AaCs2PLrIug_wyaLilqy |
| user_admin.php | 2937 | AaCs2PLrIug_wyaLilrG |
| user_admin.php | 2953 | AaCs2PLrIug_wyaLilqz |
| user_admin.php | 3034 | AaCs2PLrIug_wyaLilq4 |
| user_admin.php | 3050 | AaCs2PLrIug_wyaLilqd |
| user_admin.php | 3131 | AaCs2PLrIug_wyaLilqp |
| user_admin.php | 3147 | AaCs2PLrIug_wyaLilq9 |
| user_admin.php | 3228 | AaCs2PLrIug_wyaLilrE |
| user_group_admin.php | 2266 | AaCs2PU4Iug_wyaLilsx |
| user_group_admin.php | 2386 | AaCs2PU4Iug_wyaLilsT |
| user_group_admin.php | 2501 | AaCs2PU4Iug_wyaLilsd |
| user_group_admin.php | 2598 | AaCs2PU4Iug_wyaLils2 |
| user_group_admin.php | 2695 | AaCs2PU4Iug_wyaLilsa |
| user_group_admin.php | 808 | AaCs2PU4Iug_wyaLils7 |
| user_group_admin.php | 991 | AaCs2PU4Iug_wyaLilsg |
| user_group_admin.php | 1139 | AaCs2PU4Iug_wyaLilso |
| user_group_admin.php | 1282 | AaCs2PU4Iug_wyaLilsh |
| user_group_admin.php | 809 | AaCs2PU4Iug_wyaLilsu |
| user_group_admin.php | 992 | AaCs2PU4Iug_wyaLils0 |
| user_group_admin.php | 1140 | AaCs2PU4Iug_wyaLilsk |
| user_group_admin.php | 1283 | AaCs2PU4Iug_wyaLilsW |
| user_group_admin.php | 2256 | AaCs2PU4Iug_wyaLilsm |
| user_group_admin.php | 2360 | AaCs2PU4Iug_wyaLilsX |
| user_group_admin.php | 2376 | AaCs2PU4Iug_wyaLilsj |
| user_group_admin.php | 2476 | AaCs2PU4Iug_wyaLilsb |
| user_group_admin.php | 2492 | AaCs2PU4Iug_wyaLilsU |
| user_group_admin.php | 2573 | AaCs2PU4Iug_wyaLilsl |
| user_group_admin.php | 2589 | AaCs2PU4Iug_wyaLilst |
| user_group_admin.php | 2670 | AaCs2PU4Iug_wyaLils4 |
| user_group_admin.php | 2686 | AaCs2PU4Iug_wyaLilsf |
| user_group_admin.php | 2767 | AaCs2PU4Iug_wyaLilsZ |

