-- Tabela para registrar o valor dos saldos das wallets
CREATE TABLE IF NOT EXISTS `walletbalances` (
  `idx` int NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `created_by` int NOT NULL,
  `modified_at` datetime DEFAULT NULL,
  `modified_by` int DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  `removed_by` int DEFAULT NULL,
  `active` enum('yes','no') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'yes',
  `balance_usdc` decimal(20,8) NOT NULL DEFAULT '0.00000000' COMMENT 'Saldo total da carteira em USDC',
  `snapshot_type` enum('before_trade','after_trade','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual' COMMENT 'Tipo do snapshot',
  `trade_idx` int DEFAULT NULL COMMENT 'ID do trade relacionado (se aplicável)',
  `growth_percent` decimal(10,4) DEFAULT NULL COMMENT 'Crescimento % em relação ao snapshot anterior',
  `previous_balance` decimal(20,8) DEFAULT NULL COMMENT 'Saldo do snapshot anterior para cálculo',
  `snapshot_at` datetime NOT NULL COMMENT 'Data/hora do snapshot',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT 'Observações sobre o snapshot',
  PRIMARY KEY (`idx`),
  KEY `idx_trade` (`trade_idx`),
  KEY `idx_snapshot_type` (`snapshot_type`),
  KEY `idx_snapshot_at` (`snapshot_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Histórico de saldo da carteira';