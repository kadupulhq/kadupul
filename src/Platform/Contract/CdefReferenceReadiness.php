<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/** A caller may mutate CDEF references only after native readiness is verified. */
interface CdefReferenceReadiness
{
    public function assertReady(): void;
}
