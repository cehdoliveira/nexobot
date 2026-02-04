-- Tabela para registrar logs dos grids
CREATE TABLE IF NOT EXISTS `grid_logs` (
    `idx` INT NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME NOT NULL,
    `created_by` INT NOT NULL,
    `modified_at` DATETIME DEFAULT NULL,
    `modified_by` INT DEFAULT NULL,
    `removed_at` DATETIME DEFAULT NULL,
    `removed_by` INT DEFAULT NULL,
    `active` ENUM('yes','no') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'yes',
    `grids_id` INT NOT NULL COMMENT 'ID do grid',
    `log_type` ENUM('info','success','warning','error') COLLATE utf8mb4_unicode_ci DEFAULT 'info' COMMENT 'Tipo de log',
    `event` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Evento registrado',
    `message` TEXT COLLATE utf8mb4_unicode_ci COMMENT 'Mensagem de log',
    `data` JSON COMMENT 'Dados adicionais em JSON',
    PRIMARY KEY (`idx`),
    FOREIGN KEY (`grids_id`) REFERENCES `grids`(`idx`),
    KEY `idx_grids_id` (`grids_id`),
    KEY `idx_log_type` (`log_type`),
    KEY `idx_event` (`event`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Logs de eventos dos grids';
