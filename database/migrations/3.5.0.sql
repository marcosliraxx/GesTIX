-- Caminho: database/migrations/3.5.0.sql
-- Migração aditiva e reaplicável; adapte o prefixo arc_ se necessário.
CREATE TABLE IF NOT EXISTS `arc_support_guest_codes` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, agent_id BIGINT UNSIGNED NOT NULL,
 code_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL,
 command_id BIGINT UNSIGNED NULL UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_guest_agent(agent_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_native_accounts` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE, expires_at DATETIME NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_native_user(user_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_command_requests` (
 command_id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_schedule_managers` (
 schedule_id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @arc_remote_alter = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='arc_support_remote_sessions' AND COLUMN_NAME='unattended')=0,'ALTER TABLE `arc_support_remote_sessions` ADD COLUMN unattended TINYINT NOT NULL DEFAULT 0','DO 0');
PREPARE arc_remote_statement FROM @arc_remote_alter;
EXECUTE arc_remote_statement;
DEALLOCATE PREPARE arc_remote_statement;
