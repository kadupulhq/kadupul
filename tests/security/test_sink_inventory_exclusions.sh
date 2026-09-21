#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
mkdir -p "$fixture/tests/security" "$fixture/include/vendor/dependency" "$fixture/var/cache"
cp "$root/tests/security/build_sink_inventory.sh" "$fixture/tests/security/"
printf '<?php shell_exec($input);\n' > "$fixture/app.php"
bash "$fixture/tests/security/build_sink_inventory.sh" | LC_ALL=C sort > "$fixture/before.tsv"
# Direct children used to override the exclusion through the final *.php glob.
for file in include/vendor/index.php include/vendor/dependency/code.php var/cache/cache.php; do
    printf '<?php shell_exec($input);\n' > "$fixture/$file"
done
bash "$fixture/tests/security/build_sink_inventory.sh" | LC_ALL=C sort > "$fixture/after.tsv"
diff -u "$fixture/before.tsv" "$fixture/after.tsv"
grep -F './app.php:1' "$fixture/after.tsv" >/dev/null
printf 'PASS: installed dependencies and cache cannot change the authored sink inventory\n'
