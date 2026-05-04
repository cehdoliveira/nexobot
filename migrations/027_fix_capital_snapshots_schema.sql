-- Adicionar colunas obrigatórias do DOLModel à tabela capital_snapshots
ALTER TABLE `capital_snapshots`
    ADD COLUMN `active`       ENUM('yes', 'no') NOT NULL DEFAULT 'yes' AFTER `accumulated_spread_pnl`,
    ADD COLUMN `created_by`   INT              NOT NULL DEFAULT 0   AFTER `active`,
    ADD COLUMN `modified_at`  DATETIME         DEFAULT NULL         AFTER `created_by`,
    ADD COLUMN `modified_by`  INT              DEFAULT NULL         AFTER `modified_at`,
    ADD COLUMN `removed_at`   DATETIME         DEFAULT NULL         AFTER `modified_by`,
    ADD COLUMN `removed_by`   INT              DEFAULT NULL         AFTER `removed_at`;
