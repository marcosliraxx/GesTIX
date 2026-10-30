-- Caminho: database/migrations/3.2.0.sql
-- Aditiva e reaplicável em MySQL/MariaDB. Troque arc_ pelo prefixo configurado.
-- Preserve backup antes da atualização. Não altera cobranças já existentes.
SET @arc_table = 'arc_billing_invoices';
SET @arc_ddl = (SELECT IF(COUNT(*)=0, CONCAT('ALTER TABLE `', @arc_table, '` ADD COLUMN payment_payload TEXT NULL'), 'DO 0') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@arc_table AND COLUMN_NAME='payment_payload');
PREPARE arc_stmt FROM @arc_ddl;
EXECUTE arc_stmt;
DEALLOCATE PREPARE arc_stmt;
