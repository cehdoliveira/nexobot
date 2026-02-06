-- Adicionar o status 'rebalanced' ao enum da coluna status na tabela grids
-- Necessário para marcar grids que foram rebalanceados

ALTER TABLE `grids`
MODIFY COLUMN `status` ENUM('active','paused','stopped','rebalanced') COLLATE utf8mb4_unicode_ci DEFAULT 'active' COMMENT 'Status atual do grid';
