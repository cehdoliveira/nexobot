-- Tabela para registrar os usuários do sistema
CREATE TABLE IF NOT EXISTS `profiles` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') DEFAULT 'yes',
    `name` VARCHAR(255) DEFAULT NULL,
    `editabled` ENUM('yes', 'no') DEFAULT 'yes',
    `slug` VARCHAR(255) NOT NULL,
    `adm` ENUM('yes', 'no') DEFAULT 'no',
    `parent` INT DEFAULT '0',
    PRIMARY KEY (`idx`)
);

INSERT INTO `profiles` (`created_at`, `created_by`, `active`, `name`, `editabled`, `slug`, `adm`, `parent`) VALUES (NOW(), '0', 'yes', 'Administrador', 'yes', 'admin', 'yes', '0');