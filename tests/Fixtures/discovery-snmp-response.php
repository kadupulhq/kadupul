#!/usr/bin/env php
<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$oid = end($argv);
$values = array('.1.3.6.1.2.1.1.2.0' => 'OID: .1.3.6.1.4.1.8072.3.2.10', '.1.3.6.1.2.1.1.5.0' => 'STRING: FixtureNode', '.1.3.6.1.2.1.1.6.0' => 'STRING: FixtureLocation', '.1.3.6.1.2.1.1.4.0' => 'STRING: FixtureContact', '.1.3.6.1.2.1.1.1.0' => 'STRING: Linux diagnostic', '.1.3.6.1.6.3.10.2.1.3.0' => 'INTEGER: 123');
echo ($values[$oid] ?? 'INTEGER: 123') . "\n";
