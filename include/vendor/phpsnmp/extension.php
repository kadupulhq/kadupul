<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Alias the PHP SNMP extension to the phpsnmp namespace so it can be
 * referenced as such in lib/snmp.php.
 */

namespace phpsnmp;

class SNMP extends \SNMP {
	public $bulk_walk_size;
	public $value_output_format;
}

