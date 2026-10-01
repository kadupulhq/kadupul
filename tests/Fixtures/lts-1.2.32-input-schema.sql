-- SPDX-FileCopyrightText: 2004-2026 The Cacti Group
-- SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
-- SPDX-License-Identifier: GPL-2.0-or-later
-- Exact table definitions from lts/1.2 commit 34790c131a8cae718bfc7760096b4205dd92e665 cacti.sql.
-- Its canonical database version is 1.2.32. Only the tables exercised by this upgrade are retained.

CREATE TABLE data_template_rrd (
  id int(10) unsigned NOT NULL auto_increment,
  hash varchar(32) NOT NULL default '',
  local_data_template_rrd_id int(10) unsigned NOT NULL default '0',
  local_data_id int(10) unsigned NOT NULL default '0',
  data_template_id mediumint(8) unsigned NOT NULL default '0',
  t_rrd_maximum char(2) default NULL,
  rrd_maximum varchar(20) NOT NULL default '0',
  t_rrd_minimum char(2) default NULL,
  rrd_minimum varchar(20) NOT NULL default '0',
  t_rrd_heartbeat char(2) default NULL,
  rrd_heartbeat mediumint(6) NOT NULL default '0',
  t_data_source_type_id char(2) default NULL,
  data_source_type_id smallint(5) NOT NULL default '0',
  t_data_source_name char(2) default NULL,
  data_source_name varchar(19) NOT NULL default '',
  t_data_input_field_id char(2) default NULL,
  data_input_field_id mediumint(8) unsigned NOT NULL default '0',
  PRIMARY KEY (id),
  UNIQUE KEY `duplicate_dsname_contraint` (`local_data_id`,`data_source_name`,`data_template_id`),
  KEY data_template_id (data_template_id),
  KEY local_data_template_rrd_id (local_data_template_rrd_id)
) ENGINE=InnoDB ROW_FORMAT=Dynamic;

CREATE TABLE data_input_fields (
  id mediumint(8) unsigned NOT NULL auto_increment,
  hash varchar(32) NOT NULL default '',
  data_input_id mediumint(8) unsigned NOT NULL default '0',
  name varchar(200) NOT NULL default '',
  data_name varchar(50) NOT NULL default '',
  input_output char(3) NOT NULL default '',
  update_rra char(2) default '0',
  sequence smallint(5) NOT NULL default '0',
  type_code varchar(40) default NULL,
  regexp_match varchar(200) default NULL,
  allow_nulls char(2) default NULL,
  PRIMARY KEY (id),
  KEY data_input_id (data_input_id),
  KEY input_output (input_output),
  KEY type_code_data_input_id (type_code, data_input_id)
) ENGINE=InnoDB ROW_FORMAT=Dynamic;

CREATE TABLE settings_user (
  user_id smallint(8) unsigned NOT NULL default '0',
  name varchar(255) NOT NULL default '',
  value varchar(4096) NOT NULL default '',
  PRIMARY KEY (user_id, name)
) ENGINE=InnoDB ROW_FORMAT=Dynamic;
