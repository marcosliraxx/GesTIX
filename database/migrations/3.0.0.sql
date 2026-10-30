-- Caminho: database/migrations/3.0.0.sql
-- Migração aditiva. Ajuste arc_ caso use outro prefixo. Preserve backup.
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
