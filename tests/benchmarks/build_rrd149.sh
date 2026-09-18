#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail
prefix="${1:?Pass an installation prefix}"
source_dir="$(mktemp -d)"
trap 'rm -rf "$source_dir"' EXIT
curl --fail --location --retry 3 https://codeload.github.com/oetiker/rrdtool-1.x/tar.gz/refs/tags/v1.4.9 -o "$source_dir/source.tar.gz"
# Short options and an explicit stdin operand suit GNU and BSD sha256sum;
# macOS before 15 has only shasum.
if command -v sha256sum > /dev/null; then
	sha256_check=(sha256sum -c -)
else
	sha256_check=(shasum -a 256 -c -)
fi
printf '%s  %s\n' c125d6850c7b6a24a18676989c617e82f0e594444017c833b92da8ffa759d270 "$source_dir/source.tar.gz" | "${sha256_check[@]}"
tar xzf "$source_dir/source.tar.gz" -C "$source_dir" --strip-components=1
cd "$source_dir"
# Pin auxiliary output to this source tree; otherwise old libtoolize can
# discover install-sh in the runner's shared parent temporary directory.
# BSD sed lacks GNU's one-line insert and in-place forms.
awk '/^AM_INIT_AUTOMAKE/ { print "AC_CONFIG_AUX_DIR([.])" } { print }' configure.ac > configure.ac.new
mv configure.ac.new configure.ac
# Homebrew installs GNU libtoolize as glibtoolize.
if command -v libtoolize > /dev/null; then
	libtoolize --force --copy
else
	glibtoolize --force --copy
fi
autoreconf -fi
./configure --prefix="$prefix" --disable-perl --disable-python --disable-ruby --disable-tcl --disable-lua --disable-libdbi --disable-rrdcached
make -j2
make install
"$prefix/bin/rrdtool" --version
