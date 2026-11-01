<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$mode = $argv[1] ?? '';
if ($mode === 'empty') exit(0);
if ($mode === 'truncated') { echo '{'; exit(0); }
echo '{"completed":true}';
if ($mode === 'late-failure') exit(7);
if ($mode === 'diagnostic') { fwrite(STDERR, 'Owned fixture diagnostic.'); exit(0); }
if ($mode !== 'success') exit(2);
