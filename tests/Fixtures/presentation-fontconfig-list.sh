#!/bin/sh
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
set -eu
[ "$#" -eq 2 ]
[ "$1" = '--format' ]
[ "$2" = '%{family}\n' ]
printf 'Native Serif\nNative Sans\n'
