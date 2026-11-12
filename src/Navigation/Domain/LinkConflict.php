<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Domain;

/** Thrown when a change is based on an out-of-date view of the links, or on a link that is gone. */
final class LinkConflict extends \RuntimeException {}
