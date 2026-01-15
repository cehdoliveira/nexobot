-- Tabela para registrar o relacionamento many-to-many entre users e profiles
CREATE TABLE IF NOT EXISTS `users_profiles` (
  `idx` int NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `created_by` int NOT NULL,
  `modified_at` datetime DEFAULT NULL,
  `modified_by` int DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  `removed_by` int DEFAULT NULL,
  `active` enum('yes','no') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'yes',
  `users_id` int NOT NULL,
  `profiles_id` int NOT NULL,
  PRIMARY KEY (`idx`),
  KEY `idx_users_id` (`users_id`),
  KEY `idx_profiles_id` (`profiles_id`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Relação many-to-many entre users e profiles';