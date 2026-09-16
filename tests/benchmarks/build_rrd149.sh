#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -euo pipefail
prefix="${1:?Pass an installation prefix}"
source_dir="$(mktemp -d)"
trap 'rm -rf "$source_dir"' EXIT
curl --fail --location --retry 3 https://codeload.github.com/oetiker/rrdtool-1.x/tar.gz/refs/tags/v1.4.9 -o "$source_dir/source.tar.gz"
printf '%s  %s\n' c125d6850c7b6a24a18676989c617e82f0e594444017c833b92da8ffa759d270 "$source_dir/source.tar.gz" | sha256sum --check
tar xzf "$source_dir/source.tar.gz" -C "$source_dir" --strip-components=1
cd "$source_dir"
libtoolize --force --copy
autoreconf -fi
./configure --prefix="$prefix" --disable-perl --disable-python --disable-ruby --disable-tcl --disable-lua --disable-libdbi --disable-rrdcached
make -j2
make install
"$prefix/bin/rrdtool" --version
