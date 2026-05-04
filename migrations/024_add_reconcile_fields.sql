ALTER TABLE `orders`
    ADD COLUMN `recovered_orphan` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_maker`;

ALTER TABLE `grids`
    ADD COLUMN `pending_shutdown_at`     DATETIME     DEFAULT NULL AFTER `trailing_stop_triggered_at`,
    ADD COLUMN `pending_shutdown_reason` VARCHAR(30)  DEFAULT NULL AFTER `pending_shutdown_at`;
