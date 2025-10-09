CREATE TABLE IF NOT EXISTS `passwords` (
    `id` CHAR(36) NOT NULL,
    `usuario` VARCHAR(255) NOT NULL COMMENT 'Nome de usuario ou e-mail',
    `senha` TEXT NOT NULL COMMENT 'Senha criptografada (AES-256-GCM)',
    `link` TEXT NOT NULL COMMENT 'URL ou mailto de acesso',
    `tipo` ENUM('Sistema', 'Rede Social', 'E-mail') NOT NULL DEFAULT 'Sistema',
    `local` VARCHAR(100) NOT NULL COMMENT 'Plataforma/servico',
    `verificado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Senha validada recentemente',
    `ativo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Flag de soft delete',
    `descricao` TEXT NULL,
    `ip` VARCHAR(45) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` CHAR(36) NULL,
    `updated_by` CHAR(36) NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_passwords_tipo` (`tipo`),
    INDEX `idx_passwords_local` (`local`),
    INDEX `idx_passwords_verificado` (`verificado`),
    INDEX `idx_passwords_ativo` (`ativo`),
    INDEX `idx_passwords_created_at` (`created_at`),
    INDEX `idx_passwords_updated_at` (`updated_at`),
    INDEX `idx_passwords_created_by` (`created_by`),
    FULLTEXT INDEX `ft_passwords_search` (`usuario`, `local`, `descricao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Gestao centralizada de credenciais';

CREATE TABLE IF NOT EXISTS `password_logs` (
    `id` CHAR(36) NOT NULL,
    `password_id` CHAR(36) NOT NULL,
    `acao` ENUM(
        'created',
        'viewed',
        'updated',
        'deleted',
        'verified',
        'unverified',
        'exported',
        'password_shown'
    ) NOT NULL,
    `usuario_id` CHAR(36) NULL,
    `ip_origem` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `dados_antes` JSON NULL,
    `dados_depois` JSON NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_password_logs_password_id` (`password_id`),
    INDEX `idx_password_logs_acao` (`acao`),
    INDEX `idx_password_logs_usuario_id` (`usuario_id`),
    INDEX `idx_password_logs_created_at` (`created_at`),
    CONSTRAINT `fk_password_logs_password`
        FOREIGN KEY (`password_id`) REFERENCES `passwords` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Auditoria de acoes sobre senhas';

CREATE TABLE IF NOT EXISTS `password_tags` (
    `id` CHAR(36) NOT NULL,
    `password_id` CHAR(36) NOT NULL,
    `tag` VARCHAR(50) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_password_tag` (`password_id`, `tag`),
    INDEX `idx_password_tags_password_id` (`password_id`),
    INDEX `idx_password_tags_tag` (`tag`),
    CONSTRAINT `fk_password_tags_password`
        FOREIGN KEY (`password_id`) REFERENCES `passwords` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tags opcionais para organização';

DROP VIEW IF EXISTS `v_passwords_with_stats`;
CREATE VIEW `v_passwords_with_stats` AS
SELECT
    p.*,
    u1.name AS created_by_name,
    u2.name AS updated_by_name,
    COUNT(DISTINCT pl.id) AS total_logs,
    MAX(pl.created_at) AS last_activity
FROM passwords p
LEFT JOIN users u1 ON p.created_by = u1.id
LEFT JOIN users u2 ON p.updated_by = u2.id
LEFT JOIN password_logs pl ON p.id = pl.password_id
WHERE p.ativo = 1
GROUP BY p.id;
