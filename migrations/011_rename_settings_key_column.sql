-- Renomear coluna 'key' (reservada) para 'cfg_key' e ajustar índice único
ALTER TABLE `settings` CHANGE `key` `cfg_key` VARCHAR(100) NOT NULL;
ALTER TABLE `settings` DROP INDEX `uniq_namespace_key`;
ALTER TABLE `settings` ADD UNIQUE KEY `uniq_namespace_cfgkey` (`namespace`, `cfg_key`);
