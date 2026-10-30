-- Caminho: database/migrations/2.8.2.sql
-- Apenas cria tabelas ausentes; não altera nem exclui dados.
-- Prefixo padrão arc_: ajuste se sua instalação usa outro prefixo.

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
