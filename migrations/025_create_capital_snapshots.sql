CREATE TABLE IF NOT EXISTS `capital_snapshots` (
    `idx`                   INT          NOT NULL AUTO_INCREMENT,
    `created_at`            DATETIME     NOT NULL,
    `grids_id`              INT          NOT NULL,
    `total_capital_usdc`    DECIMAL(20,8) NOT NULL,
    `usdc_balance`          DECIMAL(20,8) NOT NULL,
    `btc_holding`           DECIMAL(20,8) NOT NULL,
    `btc_price`             DECIMAL(20,8) NOT NULL,
    `accumulated_spread_pnl` DECIMAL(20,8) NOT NULL,
    PRIMARY KEY (`idx`),
    KEY `idx_grids_created` (`grids_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
