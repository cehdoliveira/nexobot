-- Tabela de configurações da aplicação
CREATE TABLE IF NOT EXISTS `settings` (
  `idx` INT NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME NOT NULL,
  `created_by` INT NOT NULL,
  `modified_at` DATETIME DEFAULT NULL,
  `modified_by` INT DEFAULT NULL,
  `removed_at` DATETIME DEFAULT NULL,
  `removed_by` INT DEFAULT NULL,
  `active` ENUM('yes','no') NOT NULL DEFAULT 'yes',
  `namespace` VARCHAR(100) NOT NULL DEFAULT 'default' COMMENT 'Grupo/escopo das chaves',
  `key` VARCHAR(100) NOT NULL COMMENT 'Nome da configuração',
  `value` TEXT DEFAULT NULL COMMENT 'Valor (JSON/String)',
  `description` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`idx`),
  UNIQUE KEY `uniq_namespace_key` (`namespace`, `key`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configurações gerais da aplicação';
