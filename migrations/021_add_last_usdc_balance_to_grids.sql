-- Migration 021: Persistir referência do saldo USDC por grid
-- Necessário para detectar aporte automático por delta real de USDC entre ciclos

ALTER TABLE `grids`
ADD COLUMN `last_usdc_balance_usdc` DECIMAL(20,8) DEFAULT NULL
    COMMENT 'Último saldo total em USDC observado (free + locked)' AFTER `current_capital_usdc`;

INSERT INTO migrations_log (migration_name, executed_at)
VALUES ('021_add_last_usdc_balance_to_grids.sql', NOW())
ON DUPLICATE KEY UPDATE executed_at = NOW();
