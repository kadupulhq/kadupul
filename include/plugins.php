<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

global $plugin_hooks, $plugins_integrated, $plugins;
$plugin_hooks       = array();
$plugins            = array();
$plugins_integrated = array('snmpagent', 'clog', 'settings', 'boost', 'dsstats', 'watermark', 'ssl', 'ugroup', 'domains', 'jqueryskin', 'secpass', 'logrotate', 'realtime', 'rrdclean', 'nectar', 'aggregate', 'autom8', 'discovery', 'spikekill', 'superlinks', 'debug');
