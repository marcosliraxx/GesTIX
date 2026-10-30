-- Caminho: database/schema.sql
SET FOREIGN_KEY_CHECKS=0;
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS `arc_account_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_audit` (`account_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=358 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_account_login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_auth_ip` (`ip_hash`,`attempted_at`),
  KEY `idx_account_auth_identifier` (`identifier_hash`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_admins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_agent_commands` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `command_name` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','running','completed','failed','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `result_text` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_command_queue` (`agent_id`,`status`,`id`),
  KEY `idx_command_network` (`network_id`,`account_id`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=265 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_agent_download_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `site_code` char(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fingerprint` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agent_download_token` (`token_hash`),
  KEY `idx_agent_download_network` (`network_id`,`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=142 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_agent_release_metadata` (
  `release_id` char(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stable',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`release_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_agent_telemetry` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `site_code` char(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'telemetry',
  `payload_json` longtext COLLATE utf8mb4_unicode_ci,
  `captured_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agent_telemetry_network` (`network_id`,`captured_at`),
  KEY `idx_agent_telemetry_agent` (`agent_id`,`captured_at`)
) ENGINE=InnoDB AUTO_INCREMENT=67664 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_agent_terminal_jobs` (
  `command_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `request_text` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `output_text` mediumtext COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`command_id`),
  KEY `idx_terminal_agent` (`agent_id`,`command_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_id` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at_ms` bigint(20) NOT NULL,
  `level` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `message` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_alert` (`session_id`,`client_id`),
  KEY `idx_alerts_time` (`session_id`,`created_at_ms`),
  CONSTRAINT `arc_alerts_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `arc_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_time` (`created_at`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `arc_audit_logs_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `arc_admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=89 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_key` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `alias` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `mac_address` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `device_type` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'device',
  `source` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `ping_ms` decimal(12,3) DEFAULT NULL,
  `link_mbps` decimal(12,3) DEFAULT NULL,
  `is_trusted` tinyint(1) NOT NULL DEFAULT '0',
  `first_seen_ms` bigint(20) DEFAULT NULL,
  `last_seen_ms` bigint(20) DEFAULT NULL,
  `extra_json` mediumtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device` (`session_id`,`device_key`),
  KEY `idx_devices_ip` (`session_id`,`ip_address`),
  CONSTRAINT `arc_devices_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `arc_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_field_values` (
  `session_id` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_id` bigint(20) unsigned NOT NULL,
  `value_json` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`,`field_id`),
  KEY `idx_field_values_field` (`field_id`),
  CONSTRAINT `arc_field_values_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `arc_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `arc_field_values_ibfk_2` FOREIGN KEY (`field_id`) REFERENCES `arc_page_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts` (`ip_hash`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_managed_networks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `local_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `subnet` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `gateway` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `client_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `site_code` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `contact_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `contact_phone` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `notes` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `vlan` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `dns_primary` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `dns_secondary` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `public_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `monitoring_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `logo_file` varchar(90) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_managed_network_site_code` (`site_code`),
  KEY `idx_network_account` (`account_id`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_navigation_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `route_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `placement` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_roles` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user,manager,viewer',
  `sort_order` int(11) NOT NULL DEFAULT '100',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `group_id` int(10) unsigned DEFAULT NULL,
  `icon` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'circle',
  `parent_id` int(10) unsigned DEFAULT NULL,
  `target_url` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `nav_place` (`placement`,`enabled`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=155 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_network_agents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fingerprint` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `site_code` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `machine_name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `platform` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `local_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `gateway_ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `agent_version` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` enum('pending','approved','rejected','blocked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `first_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at` timestamp NULL DEFAULT NULL,
  `notes` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `machine_details` longtext COLLATE utf8mb4_unicode_ci,
  `headless_mode` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_network_agent_fingerprint` (`fingerprint`),
  KEY `idx_network_agents_owner` (`account_id`,`network_id`,`status`),
  KEY `idx_network_agents_seen` (`network_id`,`last_seen_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_network_device_inventory` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `identity_key` varchar(90) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mac` varchar(17) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `detected_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `detected_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'device',
  `custom_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `custom_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `group_name` varchar(90) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `pinned` tinyint(1) NOT NULL DEFAULT '0',
  `hidden` tinyint(1) NOT NULL DEFAULT '0',
  `managed` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'offline',
  `first_seen` bigint(20) NOT NULL DEFAULT '0',
  `last_seen` bigint(20) NOT NULL DEFAULT '0',
  `last_change` bigint(20) NOT NULL DEFAULT '0',
  `source` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `payload_json` text COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_identity` (`network_id`,`agent_id`,`identity_key`),
  KEY `ix_device_list` (`account_id`,`network_id`,`agent_id`,`hidden`,`pinned`),
  KEY `ix_device_mac` (`network_id`,`mac`)
) ENGINE=InnoDB AUTO_INCREMENT=151667 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_network_geo_points` (
  `network_id` bigint(20) NOT NULL,
  `account_id` bigint(20) NOT NULL,
  `agent_id` bigint(20) NOT NULL DEFAULT '0',
  `kind` varchar(12) NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(11,7) NOT NULL,
  `accuracy_m` float DEFAULT NULL,
  `source` varchar(40) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`network_id`,`account_id`,`agent_id`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `arc_network_sessions` (
  `session_id` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `client_label` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Dispositivo conectado',
  `first_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`),
  KEY `idx_network_sessions` (`network_id`,`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_network_team_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `member_user_id` bigint(20) unsigned NOT NULL,
  `role` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'viewer',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team_member` (`network_id`,`member_user_id`),
  KEY `idx_team_network_owner` (`account_id`,`network_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_network_user_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `preference_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `preference_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_network_user_pref` (`account_id`,`network_id`,`preference_key`),
  KEY `idx_network_user_pref` (`network_id`,`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_page_fields` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `page_id` bigint(20) unsigned NOT NULL,
  `field_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `options_json` text COLLATE utf8mb4_unicode_ci,
  `default_value` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_page_field` (`page_id`,`field_key`),
  KEY `idx_fields_order` (`page_id`,`sort_order`),
  CONSTRAINT `arc_page_fields_ibfk_1` FOREIGN KEY (`page_id`) REFERENCES `arc_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_pages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subtitle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `content` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `screen_id` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `page_type` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `nav_order` int(11) NOT NULL DEFAULT '0',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_permission_group_members` (
  `account_id` bigint(20) unsigned NOT NULL,
  `group_id` int(10) unsigned NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`),
  KEY `idx_group_members` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_permission_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(90) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `agent_tabs` varchar(400) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monitor,speed_test,devices,topology,connection,tools,diagnostic,alerts,reports,settings',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_group_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_preferences` (
  `session_id` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `preference_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `preference_value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`,`preference_key`),
  CONSTRAINT `arc_preferences_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `arc_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_pwa_installations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `installation_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `device_label` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Dispositivo PWA',
  `platform` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web',
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `installed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pwa_installation` (`installation_id`),
  KEY `idx_pwa_account_network` (`account_id`,`network_id`,`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_releases` (
  `id` char(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'application/octet-stream',
  `file_size` bigint(20) unsigned NOT NULL,
  `sha256` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_releases_platform` (`platform`,`is_active`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_samples` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_id` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `captured_at_ms` bigint(20) NOT NULL,
  `is_success` tinyint(1) NOT NULL DEFAULT '0',
  `latency_ms` decimal(12,3) DEFAULT NULL,
  `jitter_ms` decimal(12,3) DEFAULT NULL,
  `download_mbps` decimal(12,3) DEFAULT NULL,
  `upload_mbps` decimal(12,3) DEFAULT NULL,
  `loss_percent` decimal(6,2) NOT NULL DEFAULT '0.00',
  `quality_percent` decimal(6,2) NOT NULL DEFAULT '0.00',
  `detail` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sample` (`session_id`,`client_id`),
  KEY `idx_samples_time` (`session_id`,`captured_at_ms`),
  CONSTRAINT `arc_samples_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `arc_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_sessions` (
  `id` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `started_at_ms` bigint(20) DEFAULT NULL,
  `last_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_settings` (
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `value_type` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_user_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(254) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `phone` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `company_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `job_title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `timezone` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'America/Sao_Paulo',
  `avatar_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `notifications_email` tinyint(1) NOT NULL DEFAULT '1',
  `account_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `access_role` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `access_notes` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_account_email_hash` (`email_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_user_preferences` (
  `user_id` bigint(20) unsigned NOT NULL,
  `pref_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pref_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`pref_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE IF NOT EXISTS `arc_network_team_invitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `email_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(254) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'viewer',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invitation` (`network_id`,`email_hash`),
  KEY `idx_invitation_email` (`email_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `arc_billing_plans` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE, price_cents INT UNSIGNED NOT NULL, networks_limit INT UNSIGNED NOT NULL DEFAULT 3, agents_limit INT UNSIGNED NOT NULL DEFAULT 15, seats_limit INT UNSIGNED NOT NULL DEFAULT 2, enabled TINYINT NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `arc_billing_subscriptions` (
 account_id BIGINT UNSIGNED PRIMARY KEY, plan_id BIGINT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', period_end DATETIME NULL, cancel_at_period_end TINYINT NOT NULL DEFAULT 0, provider VARCHAR(20) NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_subscription_due(status,period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `arc_billing_invoices` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, account_id BIGINT UNSIGNED NOT NULL, plan_id BIGINT UNSIGNED NOT NULL, amount_cents INT UNSIGNED NOT NULL, provider VARCHAR(20) NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'pending', external_id VARCHAR(120) NULL, checkout_url TEXT NULL, reference CHAR(32) NOT NULL, period_key VARCHAR(96) NOT NULL, paid_at DATETIME NULL, receipt_note VARCHAR(255) NOT NULL DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_invoice_ref(reference), UNIQUE KEY uq_invoice_cycle(account_id,period_key), UNIQUE KEY uq_invoice_external(provider,external_id), KEY idx_invoice_account(account_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `arc_billing_customers` (
 account_id BIGINT UNSIGNED NOT NULL, provider VARCHAR(20) NOT NULL, external_id VARCHAR(120) NOT NULL, PRIMARY KEY(account_id,provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `arc_billing_events` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(20) NOT NULL, event_key CHAR(64) NOT NULL, invoice_id BIGINT UNSIGNED NULL, event_type VARCHAR(40) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_billing_event(provider,event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `arc_support_tickets` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, account_id BIGINT UNSIGNED NOT NULL, network_id BIGINT UNSIGNED NOT NULL, agent_id BIGINT UNSIGNED NULL, created_by BIGINT UNSIGNED NOT NULL, assigned_to BIGINT UNSIGNED NULL, title VARCHAR(160) NOT NULL, description TEXT NOT NULL, priority VARCHAR(16) NOT NULL DEFAULT 'normal', status VARCHAR(20) NOT NULL DEFAULT 'open', due_at DATETIME NOT NULL, first_response_at DATETIME NULL, closed_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY idx_support_queue(account_id,status,updated_at), KEY idx_support_network(network_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `arc_support_events` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ticket_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, event_type VARCHAR(30) NOT NULL, message TEXT NOT NULL, minutes_spent INT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_support_history(ticket_id,id), KEY idx_support_work(actor_id,created_at), KEY idx_support_event_time(created_at,ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `arc_support_remote_sessions` (
 command_id BIGINT UNSIGNED PRIMARY KEY, ticket_id BIGINT UNSIGNED NULL, technician_id BIGINT UNSIGNED NOT NULL, account_id BIGINT UNSIGNED NOT NULL, network_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_support_remote_account(account_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `arc_support_remote_tokens` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, command_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, code_hash CHAR(64) NOT NULL, token_hash CHAR(64) NULL, redeem_until DATETIME NOT NULL, expires_at DATETIME NOT NULL, redeemed_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_support_code(code_hash), UNIQUE KEY uq_support_token(token_hash), KEY idx_support_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
