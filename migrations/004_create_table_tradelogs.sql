-- Tabela para registrar o log de eventos dos trades
CREATE TABLE IF NOT EXISTS `tradelogs` (
  `idx` int NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `created_by` int NOT NULL,
  `modified_at` datetime DEFAULT NULL,
  `modified_by` int DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  `removed_by` int DEFAULT NULL,
  `active` enum('yes','no') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'yes',
  `trades_id` int NOT NULL COMMENT 'ID do trade relacionado',
  `log_type` enum('info','warning','error','success') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `event` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tipo de evento (ex: setup_detected, order_executed)',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Mensagem do log',
  `data` json DEFAULT NULL COMMENT 'Dados adicionais em formato JSON',
  PRIMARY KEY (`idx`),
  KEY `idx_trades_id` (`trades_id`),
  KEY `idx_log_type` (`log_type`),
  KEY `idx_event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Logs detalhados de eventos dos trades';