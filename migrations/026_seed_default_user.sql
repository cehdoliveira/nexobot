-- Seed: usuário administrador padrão e vínculo com perfil admin
INSERT IGNORE INTO `users` (`created_at`, `created_by`, `active`, `mail`, `login`, `password`, `name`, `cpf`, `enabled`)
VALUES (NOW(), 0, 'yes', 'cehd.oliveira@gmail.com', 'coliveira', MD5('@Sa131822'), 'Carlos Oliveira', '02274970157', 'yes');

INSERT IGNORE INTO `users_profiles` (`created_at`, `created_by`, `active`, `users_id`, `profiles_id`)
VALUES (NOW(), 0, 'yes', 1, 1);
