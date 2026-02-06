-- Adicionar campos necessários para tracking de ordens pareadas e processamento
-- Campos: paired_order_id, is_processed, profit_usdc

ALTER TABLE `grids_orders`
ADD COLUMN `paired_order_id` INT DEFAULT NULL COMMENT 'ID da ordem de compra/venda pareada' AFTER `grid_level`,
ADD COLUMN `is_processed` ENUM('yes','no') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'no' COMMENT 'Indica se a ordem já foi processada' AFTER `paired_order_id`,
ADD COLUMN `profit_usdc` DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Lucro obtido nesta ordem' AFTER `is_processed`;

-- Criar índices para melhorar performance
ALTER TABLE `grids_orders`
ADD KEY `idx_paired_order_id` (`paired_order_id`),
ADD KEY `idx_is_processed` (`is_processed`);

-- Adicionar constraint de foreign key para paired_order_id
ALTER TABLE `grids_orders`
ADD CONSTRAINT `fk_grids_orders_paired` FOREIGN KEY (`paired_order_id`) REFERENCES `grids_orders`(`idx`) ON DELETE SET NULL;
