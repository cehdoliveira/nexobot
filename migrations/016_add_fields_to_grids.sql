-- Adicionar campos necessários para o grid trading na tabela grids
-- Campos: capital_per_level e last_checked_at

ALTER TABLE `grids`
ADD COLUMN `capital_per_level` DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Capital alocado por nível do grid' AFTER `capital_allocated_usdc`,
ADD COLUMN `last_checked_at` DATETIME DEFAULT NULL COMMENT 'Última vez que o grid foi verificado' AFTER `current_price`;

-- Criar índice para melhorar performance
ALTER TABLE `grids`
ADD KEY `idx_last_checked_at` (`last_checked_at`);
