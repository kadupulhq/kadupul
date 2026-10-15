# Function-level behavior capture

This records what individual functions in `lib/functions.php` return and do, by
calling the real function. It extends the [behavioral harness](README.md): the
same container stack, the same `probe.php` adapter, the same per-PHP-version
golden layout. It exists so that a function moved out of `lib/functions.php`
can be checked against the behavior it had before the move, including the
behavior nobody meant.

The capture runs the application's own function. It never re-implements one. A
second hand-written copy would only certify its own mistakes.

## Running it

```sh
make test-functions            # capture, then compare with the committed goldens
make test-functions-golden     # re-record every function; review the diff
```

Neither target runs in any CI workflow. Nothing fails a pull request when a
function changes behavior, so the goldens can drift from `lib/functions.php`
until someone runs `make test-functions`. Run it before moving or editing a
captured function.

Both start a throwaway compose project named `fnwt_functions`, install the
fresh schema the way the main harness does, run every case file in
`tests/Support/Behavior/function_cases/`, and tear the project down. Pass
`--keep` to `harness.py functions` to leave it up. `--only NAME...` limits a
verification run to some functions; it cannot be combined with
`--update-golden`.

Goldens live in `tests/Golden/functions/<target>/php-<version>/<function>.json`.
They are kept apart from the scenario goldens so the main inventory check does
not count them as orphans. The raw run is written to
`tests/behavior/results/<target>/functions.json`, which is not committed.

A run fails when a case file has no cases, when a function is not defined in
the application, when the probe records fewer cases than the file lists, when a
golden is missing, and when a golden exists for a function with no case file.
Goldens are compared as text, so `1` and `1.0` are different values.

Goldens and the differential report are written with sorted keys, and a container stays on one line when its line fits in 100 columns, so the generated diffs stay reviewable; larger containers get one element per line.

## What a record holds

For each case:

| Field | Meaning |
|---|---|
| `result.returned` | `{type, value}`. `type` is PHP's `get_debug_type()`. |
| `result.exception` | Class, code and message, when the call threw. A call that never returns, stopped by `timeout`, is recorded as `BehaviorTimeout`. |
| `result.by_ref` | Final value and type of each by-reference parameter that was passed. |
| `result.diagnostics` | Warnings, notices and deprecations, one entry per distinct message with a `count`, and whether the `@` operator suppressed it. |
| `result.output` | Anything the function printed. |
| `result.log` | New lines in `log/cacti.log`, with the timestamp replaced. |
| `warm` | `{same: true}`, or `{same: false, result: ...}` when an identical second call in the same process answers differently. This is how a `static` or global cache shows up. |
| `prelude`, `between` | Records of the setup steps that ran before the call and between the two calls. |

Each function also records its `signature`: parameter names, types, defaults,
and which are by reference.

Two things keep goldens short without losing content:

- A golden leaves out a field that holds its default: `note` of `""`, `warm` of `{"same": true}`, and `result.diagnostics` of `[]`, `result.log` of `[]` and `result.output` of `""`. The harness puts them back when it reads a golden, so a record that lacks one of these still fails the comparison.
- A list or object of more than 64 elements, in `args`, `ambient`, `returned` or `by_ref`, is written as `{"$": "list", "count": N, "types": [...], "head": [first 4], "sha256": "..."}` (`"$": "object"` for a map, with the first 4 keys in sorted order). The hash covers the whole value in canonical form, so changing one element, or `1` into `1.0`, changes the golden. Lists inside a smaller container are summarized on their own. The case file still holds the full input.

Every case runs inside a database savepoint that is rolled back, and `$config`
and `$_SESSION` are restored, so cases do not feed one another.

Diagnostics are recorded by a handler that replaces `CactiErrorHandler` for the
duration of the call. The Cacti handler only logs; chaining to it would write
log lines for every warning.

## Case files

One JSON file per function, named after it:

```json
{
  "function": "title_trim",
  "cases": [
    {"id": "ascii_over_by_one", "args": ["abcdef", 5]},
    {"id": "null_text", "args": [null, 5], "note": "strlen(null) deprecation"}
  ]
}
```

| Key | Meaning |
|---|---|
| `id` | Unique within the file. Required. |
| `args` | List of arguments, in the value encoding below. Required. |
| `note` | Why the case exists. Shown in the comparison report. |
| `ambient.settings` | `{name: string or null}` written to the `settings` table before the call. `null` deletes the row. The cached copy is dropped so the call reads the table. |
| `ambient.config` | `{key: value}` set in `$config` for the case. |
| `ambient.session` | `{key: value}` set in `$_SESSION` for the case. |
| `prelude` | Steps run before the call: `{"fn": name, "args": [...]}` calls a function defined by the application, `{"setting": {name: value}}` writes a setting. Internal PHP functions such as `shell_exec` are refused. |
| `between` | Steps run between the first call and the repeat. A setting written here does not drop the cache, which is the point. |
| `isolated` | `true` or `false`. Run this case in its own PHP process. Use it for anything that depends on a cold cache. |
| `timeout` | A positive whole number of seconds after which the call is abandoned and recorded as `BehaviorTimeout`. |

Unknown keys are rejected, so a typo or a `skip` flag cannot turn a case into a
silent pass. The loader also checks that `ambient` and each of its sections are
objects, that `ambient.settings` values are strings or `null`, that `prelude` and
`between` are lists of steps with only the keys above, and that `isolated` and
`timeout` have the types in the table. Anything else stops the run before a
container starts.

### Value encoding

JSON cannot say `'0'` instead of `0`, cannot hold invalid UTF-8, and has no NaN,
INF or `-0.0`. The case files and the records share one tagged encoding, defined
in `function_codec.php`:

| JSON | PHP value |
|---|---|
| `null`, `true`, `false`, numbers | The same type. `1` is an int, `1.0` is a float. |
| A string | A string. In a record, strings with control bytes or invalid UTF-8 are written as `bytes` instead. |
| `[a, b]` | A list. |
| `{"$":"array","pairs":[[k,v],...]}` | Any other array, in order. Keys are ints or strings. |
| `{"$":"bytes","hex":"c328"}` | A string, byte for byte. |
| `{"$":"float","v":"nan"}` | `nan`, `inf`, `-inf` or `-0.0`. |
| `{"$":"repeat","unit":"ab","count":4096}` | Input only. A long string without a long file. |
| `{"$":"long","bytes":N,"sha256":"...","head_hex":"..."}` | Output only. A string over 4096 bytes, recorded by digest. |

A bare JSON object without `"$"` is rejected, because it would be read as a
list or a map by guesswork.

## Adding a function

1. Read the function in `lib/functions.php`. List its branches.
2. Write `function_cases/<name>.json` with at least one case per branch, plus
   the edge cases that matter: empty string, `'0'`, `null`, an array where a
   scalar is expected, multibyte text, invalid UTF-8, oversized input, and
   boundary values on each comparison.
3. If the function reads a setting, session value or `$config` key, set it in
   `ambient`. If its answer depends on a cache, mark those cases `isolated`
   and use `between` to show the stale read.
4. Run `make test-functions-golden` and read the new golden. A surprising
   result is kept and explained in the case `note`; it is not fixed here.
5. Check which lines the cases reached (below) and add cases for any that are
   reachable.

Functions that need more than the database and the session, such as ones that
send mail, run processes or write files, are not covered by this mode. Classify
them instead of forcing a case.

## Checking branch coverage

Line coverage with pcov is the quickest check that no branch was missed. The
harness image does not include pcov. In a kept stack, install it in the
container and run the probe with `coverage.php` prepended:

```sh
python tests/Support/Behavior/harness.py functions --keep
docker compose -p fnwt_functions -f tests/behavior/compose.yml exec -T web \
    sh -c 'pecl install pcov-1.0.12 && docker-php-ext-enable pcov && mkdir -p /coverage && chmod 777 /coverage'
docker compose -p fnwt_functions -f tests/behavior/compose.yml exec -T -u www-data web \
    php -d auto_prepend_file=/harness/coverage.php -d pcov.directory=/var/www/html \
    /harness/probe.php function title_trim
```

Each run leaves a JSON file in `/coverage` listing executed lines per file.
Lines inside a function that no case executed are either missing cases or code
that cannot run in the image, such as a fallback for a missing PHP extension.
Both belong in the function's notes.

## Comparing with another implementation

`functions-reference` runs the same probe and case files against a running
instance whose code is elsewhere, for example a Cacti `develop` checkout. The
harness image builds from a Kadupul tree, so it cannot be pointed at that
checkout; this copies the probe into the instance's `/tmp` and runs it as the
web user. Every case is rolled back, and the log path is redirected to a file
in `/tmp`, so the instance is left as it was found.

```sh
python tests/Support/Behavior/harness.py functions-reference \
    --target cacti-develop --container fnref_web --app-dir /var/www/html/cacti
python tests/Support/Behavior/harness.py functions-compare \
    --baseline cacti-develop --candidate kadupul
```

The report lists each function and case as `same` or `differs`, with both
values for the ones that differ. A case present only in the candidate is listed
under `only_in_candidate_cases` and makes the comparison exit with status 1,
because the baseline never ran it. Differences are written as `unreviewed`. The
tool does not decide whether one is intended or a defect on either side.

Two cautions when reading a difference. The same function name can carry
different behavior, because Kadupul forked from 1.2.31 and `develop` has moved
on. And an ambient setting only works if the target reads it from where the
case writes it: `clean_up_path` in `develop` reads the `CACTI_SERVER_OS`
constant, so cases that set `$config['cacti_server_os']` differ for that reason
alone.

## Self-tests

`selftest.py` covers the new code with a stand-in application, so it needs only
a host PHP with `pcntl` (`make test-harness-selftest` provides one through
`mise`). It checks that:

- the value encoding keeps `null`, `''`, `'0'`, ints, floats, bytes and key order
  apart, and rejects malformed tags;
- an empty case file, a missing function, a duplicate id, an unknown key, a
  malformed step, `timeout`, `isolated` or `ambient`, or a probe that records
  fewer cases than listed fails the run;
- ambient settings, `$config` and `$_SESSION` reach the function and are gone
  for the next case, `prelude` and `between` run (a setting written between the
  calls leaves the cache alone), isolated cases get their own process, a step
  cannot name an internal PHP function, and a failed `SAVEPOINT`, rollback or
  transaction start stops the run;
- two captures of the same cases are byte for byte identical;
- a golden fails when it is missing, when `1` becomes `1.0`, or when its case
  file is gone;
- the comparison marks `1` against `1.0` as different, leaves it unreviewed, and
  fails when the two sides share no function or the candidate has a case the
  baseline lacks;
- normalisation leaves a case's own `id`, `args`, `ambient` and `note` alone but
  still normalises a by-reference parameter that happens to carry one of those
  names.
