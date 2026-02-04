-- Tabela para registrar o relacionamento many-to-many entre grids e orders
CREATE TABLE IF NOT EXISTS `grids_orders` (
  `idx` int NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `created_by` int NOT NULL,
  `modified_at` datetime DEFAULT NULL,
  `modified_by` int DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  `removed_by` int DEFAULT NULL,
  `active` enum('yes','no') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'yes',
  `grids_id` int NOT NULL,
  `orders_id` int NOT NULL,
  PRIMARY KEY (`idx`),
  KEY `idx_grids_id` (`grids_id`),
  KEY `idx_orders_id` (`orders_id`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Relação many-to-many entre grids e orders';
