#!/bin/sh
# SPDX-FileCopyrightText: 2004-2025 The Cacti Group
# SPDX-License-Identifier: GPL-2.0-or-later

for file in `ls -1 po/*.po`;do
  ofile=$(basename --suffix=.po ${file})
  echo "Converting $file to LC_MESSAGES/${ofile}.mo"
  msgfmt ${file} -o LC_MESSAGES/${ofile}.mo
done
