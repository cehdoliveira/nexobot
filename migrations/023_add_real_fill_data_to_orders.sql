ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `commission`                   DECIMAL(20,8) DEFAULT NULL AFTER `cumulative_quote_qty`,
    ADD COLUMN IF NOT EXISTS `commission_asset`             VARCHAR(10)   DEFAULT NULL AFTER `commission`,
    ADD COLUMN IF NOT EXISTS `commission_usdc_equivalent`   DECIMAL(20,8) DEFAULT NULL AFTER `commission_asset`,
    ADD COLUMN IF NOT EXISTS `is_maker`                     TINYINT(1)    DEFAULT NULL AFTER `commission_usdc_equivalent`;
