-- SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
-- SPDX-License-Identifier: GPL-3.0-or-later

CREATE TABLE IF NOT EXISTS `asset_registry` (
  `id` int(10) unsigned NOT NULL,
  `revision` int(10) unsigned NOT NULL,
  `policy` longtext NOT NULL,
  `sources` longtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `asset_record` (
  `kind` varchar(16) NOT NULL,
  `record_id` varchar(65) NOT NULL,
  `data` longtext NOT NULL,
  PRIMARY KEY (`kind`,`record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `asset_event` (
  `sequence` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` char(32) NOT NULL,
  `data` longtext NOT NULL,
  PRIMARY KEY (`sequence`),
  UNIQUE KEY `asset_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `asset_observation` (
  `sequence` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` char(32) NOT NULL,
  `identity_id` char(64) NOT NULL,
  `data` longtext NOT NULL,
  PRIMARY KEY (`sequence`),
  UNIQUE KEY `asset_run_identity` (`run_id`,`identity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT INTO `asset_registry` (`id`,`revision`,`policy`,`sources`)
SELECT 1,0,'{}','{}' WHERE NOT EXISTS (SELECT 1 FROM `asset_registry` WHERE `id`=1);
