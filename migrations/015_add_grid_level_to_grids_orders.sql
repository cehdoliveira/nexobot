-- Adicionar coluna grid_level à tabela grids_orders
-- Esta coluna armazena o nível do grid (1, 2, 3, etc.) para cada ordem

ALTER TABLE `grids_orders`
ADD COLUMN `grid_level` INT NOT NULL DEFAULT 1 COMMENT 'Nível do grid (1=próximo ao preço central, aumenta conforme se afasta)' AFTER `orders_id`;

-- Criar índice para melhorar performance de queries
ALTER TABLE `grids_orders`
ADD KEY `idx_grid_level` (`grid_level`);
