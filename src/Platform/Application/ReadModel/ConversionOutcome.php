<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

enum ConversionOutcome
{
    case Completed;
    case SkipTableMissing;
    case InnodbDisabled;
    case FilePerTableDisabled;
}
