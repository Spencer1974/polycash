ALTER TABLE `currency_accounts` DROP COLUMN `backups_enabled`;
ALTER TABLE `users` ADD COLUMN `backups_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `unsubscribed`;
