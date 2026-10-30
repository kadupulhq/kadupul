# Windows RRDtool response trailers

Issue #781 affects the LTS Windows response parser. Character-set trimming can
remove payload bytes and leave timed `OK u:` trailers attached. The fix removes
only a complete final bare or timed success trailer, preserving every preceding
byte. Incomplete or absent trailers remain unchanged. Unix behavior is retained.

Main already has the equivalent fix in #911. Its existing 58-case PHPUnit
regression passed on PHP 8.4.25 at `9b9bbfdd4f` (174 assertions). The LTS backport
uses the same payload and framing cases under the existing Pest runner: 49 of
58 cases failed before the change; all 58 pass on PHP 8.1.34 and PHP 8.4.25.
The complete production library also passes all 58 cases locally on Darwin.

Run the portable checks with locked test dependencies installed:

```sh
cd tests
mise exec php@8.1.34 -- php vendor/bin/pest --test-directory=. --configuration phpunit.xml Unit/Core/Rrd/RrdOutputTrailerTest.php
mise exec php@8.1.34 -- php integration/rrd_output_trailers.php
```

The existing Windows storage CI job runs the complete-library probe with
`--native-windows`, which rejects execution on another operating system. Native
Windows execution and current-head CI remain pending until that job completes.
The fixture explicitly covers binary bytes, empty payloads, terminal O/K/space/CR/LF,
LF and CRLF framing, earlier status text, malformed tails and the Unix contract.
An empty case set fails rather than reporting a pass.

PHP lint, both sink inventory guards and workflow structure validation pass.
Full actionlint still reports the same pre-existing ShellCheck diagnostics as
the unchanged workflow; no new diagnostic was introduced or suppressed.

Deploy `lib/rrd.php` normally; no schema, dependency or RRD-data migration is
required. Revert the parser hunk for application rollback. Re-render affected
graphs after deployment. This correction does not repair previously cached
corrupt images and does not address the Windows one-shot I/O scope in #782.

Source base: `0bef0bb7bab`. Tested `lib/rrd.php` SHA-256: `465f31145f347e80b1ca16c288852a498327f700dee8625fd8b4f4f9eaa85f90`.
