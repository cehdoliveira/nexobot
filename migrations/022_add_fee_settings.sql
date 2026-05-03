INSERT INTO settings (namespace, `key`, value, created_at)
VALUES
  ('binance', 'fee_maker', '0.001', NOW()),
  ('binance', 'fee_taker', '0.001', NOW())
ON DUPLICATE KEY UPDATE created_at = created_at;
