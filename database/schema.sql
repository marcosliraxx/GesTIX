-- Caminho: database/schema.sql
-- Estrutura consolidada, sem registros, credenciais ou sequências de produção.
SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE IF NOT EXISTS `arc_account_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_audit` (`account_id`,`created_at`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_account_login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_account_auth_ip` (`ip_hash`,`attempted_at`),
  KEY `idx_account_auth_identifier` (`identifier_hash`,`attempted_at`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_admins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_agent_clean_inventory` (
  `agent_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'clean',
  `network_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `report_json` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `collected_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`agent_id`,`kind`),
  KEY `idx_clean_network` (`network_id`,`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_agent_clean_inventory_bkp_20261104` (
  `agent_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'clean',
  `network_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `report_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `collected_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
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
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_agent_release_metadata` (
  `release_id` char(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stable',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`release_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_agent_removal_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fingerprint` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_code` char(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agent_removal` (`fingerprint`,`token_hash`),
  KEY `idx_agent_removal_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_agent_software_inventory` (
  `agent_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `programs_json` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `collected_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`agent_id`),
  KEY `idx_programs_network` (`network_id`,`account_id`)
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
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_agent_terminal_jobs` (
  `command_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `request_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
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
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_billing_customers` (
  `account_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_id` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`account_id`,`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_billing_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_key` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_id` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_billing_event` (`provider`,`event_key`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_billing_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned NOT NULL,
  `amount_cents` int(10) unsigned NOT NULL,
  `provider` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `external_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkout_url` text COLLATE utf8mb4_unicode_ci,
  `reference` char(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_key` varchar(96) COLLATE utf8mb4_unicode_ci NOT NULL,
  `paid_at` datetime DEFAULT NULL,
  `receipt_note` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `payment_payload` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoice_ref` (`reference`),
  UNIQUE KEY `uq_invoice_cycle` (`account_id`,`period_key`),
  UNIQUE KEY `uq_invoice_external` (`provider`,`external_id`),
  KEY `idx_invoice_account` (`account_id`,`created_at`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_billing_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_cents` int(10) unsigned NOT NULL,
  `networks_limit` int(10) unsigned NOT NULL DEFAULT '3',
  `agents_limit` int(10) unsigned NOT NULL DEFAULT '15',
  `seats_limit` int(10) unsigned NOT NULL DEFAULT '2',
  `enabled` tinyint(4) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_billing_subscriptions` (
  `account_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `period_end` datetime DEFAULT NULL,
  `cancel_at_period_end` tinyint(4) NOT NULL DEFAULT '0',
  `provider` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`),
  KEY `idx_subscription_due` (`status`,`period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_clean_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `mode` enum('scan','execute') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scan',
  `age_days` smallint(5) unsigned NOT NULL DEFAULT '7',
  `file_count` int(10) unsigned NOT NULL DEFAULT '0',
  `bytes` bigint(20) unsigned NOT NULL DEFAULT '0',
  `errors` int(10) unsigned NOT NULL DEFAULT '0',
  `categories` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clean_history_agent` (`agent_id`,`id`),
  KEY `idx_clean_history_network` (`network_id`,`account_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_clean_settings` (
  `agent_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `settings_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`agent_id`),
  KEY `idx_clean_settings_network` (`network_id`,`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
CREATE TABLE IF NOT EXISTS `arc_file_browser_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `command_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `requested_by` bigint(20) unsigned NOT NULL,
  `path_text` varchar(1024) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `result_json` mediumtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `command_id` (`command_id`),
  KEY `request_lookup` (`network_id`,`agent_id`,`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4;
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
  `doc_number` varchar(18) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trade_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_email` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zip_code` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state_code` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_extra` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `district_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_status` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `main_activity` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legal_nature` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opening_date` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_managed_network_site_code` (`site_code`),
  KEY `idx_network_account` (`account_id`,`is_active`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_navigation_backup` (
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
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `devices` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'desktop,tablet,mobile',
  `mobile_footer` tinyint(4) NOT NULL DEFAULT '0',
  `mobile_primary` tinyint(4) NOT NULL DEFAULT '0',
  `context` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'global',
  `agent_permission` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile_primary_slot` tinyint(4) GENERATED ALWAYS AS (if((`mobile_primary` = 1),1,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mobile_primary_slot` (`mobile_primary_slot`),
  KEY `nav_place` (`placement`,`enabled`,`sort_order`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_network_access_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_role` enum('viewer','operator','administrator') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'viewer',
  `permissions_json` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_network_profile_name` (`network_id`,`name`),
  KEY `idx_network_profile_owner` (`account_id`,`network_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `ui_locked` tinyint(1) NOT NULL DEFAULT '0',
  `uninstall_requested` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_network_agent_fingerprint` (`fingerprint`),
  KEY `idx_network_agents_owner` (`account_id`,`network_id`,`status`),
  KEY `idx_network_agents_seen` (`network_id`,`last_seen_at`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
CREATE TABLE IF NOT EXISTS `arc_network_team_invitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `email_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(254) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'viewer',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `profile_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invitation` (`network_id`,`email_hash`),
  KEY `idx_invitation_email` (`email_hash`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_network_team_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `member_user_id` bigint(20) unsigned NOT NULL,
  `role` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'viewer',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `profile_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team_member` (`network_id`,`member_user_id`),
  KEY `idx_team_network_owner` (`account_id`,`network_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
CREATE TABLE IF NOT EXISTS `arc_platform_release_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(32) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `notes` text NOT NULL,
  `source` varchar(16) NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `files_count` int(11) NOT NULL DEFAULT '0',
  `changes_json` mediumtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `version` (`version`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `arc_platform_release_state` (
  `id` tinyint(4) NOT NULL,
  `version` varchar(32) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `files_json` mediumtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
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
CREATE TABLE IF NOT EXISTS `arc_support_command_requests` (
  `command_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`command_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) unsigned NOT NULL,
  `event_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `minutes_spent` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_history` (`ticket_id`,`id`),
  KEY `idx_support_work` (`actor_id`,`created_at`),
  KEY `idx_support_event_time` (`created_at`,`ticket_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_file_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `command_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `requested_by` bigint(20) unsigned NOT NULL,
  `direction` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `filename` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `file_size` int(10) unsigned NOT NULL DEFAULT '0',
  `sha256` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `storage_key` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `client_token` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `command_id` (`command_id`),
  UNIQUE KEY `uq_file_client_token` (`requested_by`,`client_token`),
  KEY `idx_files_requester` (`requested_by`,`network_id`,`agent_id`,`id`),
  KEY `idx_files_expiry` (`expires_at`),
  KEY `idx_files_quota` (`account_id`,`expires_at`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_guest_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint(20) unsigned NOT NULL,
  `code_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `command_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code_hash` (`code_hash`),
  UNIQUE KEY `command_id` (`command_id`),
  KEY `idx_guest_agent` (`agent_id`,`expires_at`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_native_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_native_user` (`user_id`,`expires_at`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_remote_sessions` (
  `command_id` bigint(20) unsigned NOT NULL,
  `ticket_id` bigint(20) unsigned DEFAULT NULL,
  `technician_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unattended` tinyint(4) NOT NULL DEFAULT '0',
  PRIMARY KEY (`command_id`),
  KEY `idx_support_remote_account` (`account_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_remote_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `command_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `code_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `redeem_until` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `redeemed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_code` (`code_hash`),
  UNIQUE KEY `uq_support_token` (`token_hash`),
  KEY `idx_support_expiry` (`expires_at`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_schedule_managers` (
  `schedule_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`schedule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_schedule_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` bigint(20) unsigned NOT NULL,
  `scheduled_for` datetime NOT NULL,
  `command_id` bigint(20) unsigned DEFAULT NULL,
  `outcome` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_schedule_slot` (`schedule_id`,`scheduled_for`),
  UNIQUE KEY `command_id` (`command_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `command_name` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recurrence` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime DEFAULT NULL,
  `weekdays` varchar(13) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `interval_minutes` int(10) unsigned NOT NULL DEFAULT '60',
  `next_run_at` datetime DEFAULT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_schedule_agent` (`agent_id`,`enabled`,`next_run_at`),
  KEY `idx_schedule_account` (`account_id`,`network_id`,`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_tickets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `network_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `assigned_to` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `due_at` datetime NOT NULL,
  `first_response_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_queue` (`account_id`,`status`,`updated_at`),
  KEY `idx_support_network` (`network_id`,`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_user_preferences` (
  `user_id` bigint(20) unsigned NOT NULL,
  `pref_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pref_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`pref_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=1;
