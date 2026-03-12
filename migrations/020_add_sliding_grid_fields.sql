-- Migration 020: Adicionar campos do Sliding Grid
-- Necessário para: slideGrid(), rastreaamento de slides e profit de níveis reciclados

-- 1. Adicionar campos de sliding na tabela grids_orders
ALTER TABLE `grids_orders`
ADD COLUMN `is_sliding_level` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Indica se esta ordem foi criada por um evento de slide (1 = sim)' AFTER `profit_usdc`,
ADD COLUMN `original_cost_price` DECIMAL(20,8) DEFAULT NULL
    COMMENT 'Preço de custo original do BTC reciclado (usado para calcular lucro real)' AFTER `is_sliding_level`;

-- 2. Adicionar contadores de slide na tabela grids
ALTER TABLE `grids`
ADD COLUMN `slide_count` INT NOT NULL DEFAULT 0
    COMMENT 'Total de eventos de slide realizados' AFTER `last_checked_at`,
ADD COLUMN `slide_count_down` INT NOT NULL DEFAULT 0
    COMMENT 'Slides para baixo (preço caiu abaixo da menor BUY)' AFTER `slide_count`,
ADD COLUMN `slide_count_up` INT NOT NULL DEFAULT 0
    COMMENT 'Slides para cima (preço subiu acima da maior SELL)' AFTER `slide_count_down`;

-- 3. Índice para consultas de sliding levels
ALTER TABLE `grids_orders`
ADD KEY `idx_is_sliding_level` (`is_sliding_level`);

-- 4. Registrar migration
INSERT INTO migrations_log (migration_name, executed_at)
VALUES ('020_add_sliding_grid_fields.sql', NOW())
ON DUPLICATE KEY UPDATE executed_at = NOW();
