ALTER TABLE `orders`
    ADD COLUMN `commission`                   DECIMAL(20,8) DEFAULT NULL AFTER `cumulative_quote_qty`,
    ADD COLUMN `commission_asset`             VARCHAR(10)   DEFAULT NULL AFTER `commission`,
    ADD COLUMN `commission_usdc_equivalent`   DECIMAL(20,8) DEFAULT NULL AFTER `commission_asset`,
    ADD COLUMN `is_maker`                     TINYINT(1)    DEFAULT NULL AFTER `commission_usdc_equivalent`;
