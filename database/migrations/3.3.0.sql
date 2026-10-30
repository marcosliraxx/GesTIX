-- Caminho: database/migrations/3.3.0.sql
-- Migração aditiva e reaplicável. Ajuste arc_ ao prefixo configurado. Preserve backup.
CREATE TABLE IF NOT EXISTS `arc_support_file_transfers` (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 command_id BIGINT UNSIGNED NOT NULL UNIQUE,
 account_id BIGINT UNSIGNED NOT NULL, network_id BIGINT UNSIGNED NOT NULL,
 agent_id BIGINT UNSIGNED NOT NULL, requested_by BIGINT UNSIGNED NOT NULL,
 direction VARCHAR(16) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending',
 filename VARCHAR(180) NOT NULL DEFAULT '', file_size INT UNSIGNED NOT NULL DEFAULT 0,
 sha256 CHAR(64) NULL, storage_key CHAR(64) NULL,
 message VARCHAR(255) NOT NULL DEFAULT '',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_files_requester(requested_by,network_id,agent_id,id),
 KEY idx_files_expiry(expires_at), KEY idx_files_quota(account_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
