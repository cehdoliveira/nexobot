-- Tabela para registrar os usuários do sistema
CREATE TABLE IF NOT EXISTS `users` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    `mail` VARCHAR(255) NOT NULL DEFAULT '-',
    `login` VARCHAR(255) DEFAULT NULL,
    `password` VARCHAR(255) DEFAULT NULL,
    `name` VARCHAR(255) DEFAULT NULL,
    `cpf` VARCHAR(255) NOT NULL DEFAULT '-',
    `last_login` DATETIME DEFAULT NULL,
    `phone` VARCHAR(255) DEFAULT NULL,
    `genre` ENUM('wait', 'male', 'female') NOT NULL DEFAULT 'wait',
    `enabled` ENUM('yes', 'no') NOT NULL DEFAULT 'yes',
    PRIMARY KEY (`idx`),
    UNIQUE KEY `mail_UNIQUE` (`mail`),
    UNIQUE KEY `cpf_UNIQUE` (`cpf`)
);