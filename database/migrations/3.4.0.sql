-- Caminho: database/migrations/3.4.0.sql
-- Migração aditiva e reaplicável; adapte arc_ ao prefixo instalado. Preserve backup.
CREATE TABLE IF NOT EXISTS `arc_support_schedules` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 account_id BIGINT UNSIGNED NOT NULL, network_id BIGINT UNSIGNED NOT NULL,
 agent_id BIGINT UNSIGNED NOT NULL, command_name VARCHAR(40) NOT NULL,
 recurrence VARCHAR(16) NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NULL,
 weekdays VARCHAR(13) NOT NULL DEFAULT '', interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
 next_run_at DATETIME NULL, enabled TINYINT NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_schedule_agent(agent_id,enabled,next_run_at), KEY idx_schedule_account(account_id,network_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `arc_support_schedule_runs` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, schedule_id BIGINT UNSIGNED NOT NULL,
 scheduled_for DATETIME NOT NULL, command_id BIGINT UNSIGNED NULL UNIQUE,
 outcome VARCHAR(24) NOT NULL, message VARCHAR(255) NOT NULL DEFAULT '',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY idx_schedule_slot(schedule_id,scheduled_for)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
