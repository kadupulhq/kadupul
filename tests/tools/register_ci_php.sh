#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
# Register the preserved setup-php binary under its actual stable patch version.
set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "Usage: register_ci_php.sh PRESERVED_PHP_DIRECTORY EXPECTED_MAJOR.MINOR" >&2
    exit 1
fi
php_directory=$1
expected_series=$2
: "${GITHUB_ENV:?GitHub environment file is required}"
if [[ ! "$expected_series" =~ ^[0-9]+\.[0-9]+$ ]]; then
    echo "Expected a PHP major.minor series" >&2
    exit 1
fi
version=$("$php_directory/bin/php" -r 'echo PHP_VERSION;')
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || [[ "$version" != "$expected_series."* ]]; then
    echo "Expected stable PHP $expected_series.x, got $version" >&2
    exit 1
fi
mise plugins install --force php https://github.com/mise-plugins/asdf-php.git#e361a2c912a431393b7deb3ecf44b877900d889c
mise link "php@$version" "$php_directory"
selected_version=$(mise exec "php@$version" -- php -r 'echo PHP_VERSION;')
if [ "$selected_version" != "$version" ]; then
    echo "mise selected PHP $selected_version instead of $version" >&2
    exit 1
fi
printf 'Verified mise PHP %s (requested series %s)\n' "$version" "$expected_series"
printf 'KADUPUL_CI_PHP_VERSION=%s\n' "$version" >> "$GITHUB_ENV"
