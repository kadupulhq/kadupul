#!/bin/sh
# SPDX-FileCopyrightText: 2004-2025 The Cacti Group
# SPDX-License-Identifier: GPL-2.0-or-later

set -eu

for file in po/*.po; do
  ofile=$(basename "$file" .po)
  echo "Converting $file to LC_MESSAGES/${ofile}.mo"
  msgfmt "$file" -o "LC_MESSAGES/${ofile}.mo"
done
