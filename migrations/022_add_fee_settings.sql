-- Configuração da taxa de fee por operação
INSERT INTO `settings` (`created_at`, `created_by`, `namespace`, `cfg_key`, `value`, `description`)
VALUES (NOW(), 1, 'default', 'fee_rate', '0.001', 'Taxa de fee por operação (0.1% = 0.001)')
ON DUPLICATE KEY UPDATE `value` = `value`;
