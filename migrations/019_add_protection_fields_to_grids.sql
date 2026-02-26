-- Migration 019: Adicionar campos de proteção ao grid trading
-- Necessário para: Stop-Loss, Trailing Stop, Race Condition Protection, Enhanced Logs

-- 1. Adicionar campos de tracking de capital (Stop-Loss + Trailing Stop)
ALTER TABLE `grids`
ADD COLUMN `initial_capital_usdc` DECIMAL(20,8) DEFAULT NULL COMMENT 'Capital inicial quando grid foi criado' AFTER `current_price`,
ADD COLUMN `peak_capital_usdc` DECIMAL(20,8) DEFAULT NULL COMMENT 'Pico mais alto de capital atingido' AFTER `initial_capital_usdc`,
ADD COLUMN `current_capital_usdc` DECIMAL(20,8) DEFAULT NULL COMMENT 'Capital atual (USDC + valor BTC em USDC)' AFTER `peak_capital_usdc`;

-- 2. Adicionar campos de Stop-Loss
ALTER TABLE `grids`
ADD COLUMN `stop_loss_triggered` ENUM('yes','no') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'no' COMMENT 'Se o stop-loss foi acionado' AFTER `current_capital_usdc`,
ADD COLUMN `stop_loss_triggered_at` DATETIME DEFAULT NULL COMMENT 'Quando o stop-loss foi acionado' AFTER `stop_loss_triggered`;

-- 3. Adicionar campos de Trailing Stop
ALTER TABLE `grids`
ADD COLUMN `trailing_stop_triggered` ENUM('yes','no') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'no' COMMENT 'Se o trailing stop foi acionado' AFTER `stop_loss_triggered_at`,
ADD COLUMN `trailing_stop_triggered_at` DATETIME DEFAULT NULL COMMENT 'Quando o trailing stop foi acionado' AFTER `trailing_stop_triggered`;

-- 4. Adicionar campos de Race Condition Protection
ALTER TABLE `grids`
ADD COLUMN `is_processing` ENUM('yes','no') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'no' COMMENT 'Se grid está sendo processado por outra instância' AFTER `trailing_stop_triggered_at`,
ADD COLUMN `last_monitor_at` DATETIME DEFAULT NULL COMMENT 'Última vez que o grid começou a ser monitorado' AFTER `is_processing`;

-- 5. Atualizar ENUM de status para incluir 'cancelled' (usado no resetCurrentGrid)
ALTER TABLE `grids`
MODIFY COLUMN `status` ENUM('active','paused','stopped','rebalanced','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'active' COMMENT 'Status atual do grid';

-- 6. Índices para performance
ALTER TABLE `grids`
ADD KEY `idx_is_processing` (`is_processing`),
ADD KEY `idx_last_monitor_at` (`last_monitor_at`),
ADD KEY `idx_stop_loss_triggered` (`stop_loss_triggered`),
ADD KEY `idx_trailing_stop_triggered` (`trailing_stop_triggered`);
