#!/bin/sh
# SPDX-FileCopyrightText: 2004-2026 The Cacti Group
# SPDX-License-Identifier: GPL-2.0-or-later

df -k $1 | grep -v Filesystem| awk '{printf "megabytes:" $4 " percent:" int($5)}'
