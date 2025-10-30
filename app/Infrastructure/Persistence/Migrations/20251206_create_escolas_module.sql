CREATE TABLE IF NOT EXISTS `cidades` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(120) NOT NULL,
    `sigla_uf` CHAR(2) NOT NULL,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_cidades_nome_uf` (`nome`, `sigla_uf`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cadastro de cidades atendidas';

CREATE TABLE IF NOT EXISTS `bairros` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cidade_id` INT UNSIGNED NOT NULL,
    `nome` VARCHAR(150) NOT NULL,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_bairros_cidade_nome` (`cidade_id`, `nome`),
    KEY `idx_bairros_cidade` (`cidade_id`),
    CONSTRAINT `fk_bairros_cidades`
        FOREIGN KEY (`cidade_id`) REFERENCES `cidades` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cadastro de bairros';

CREATE TABLE IF NOT EXISTS `usuarios` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(150) NOT NULL,
    `email` VARCHAR(180) NOT NULL,
    `perfil` VARCHAR(60) NOT NULL,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_usuarios_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Usuarios autenticados';

CREATE TABLE IF NOT EXISTS `escolas` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cidade_id` INT UNSIGNED NOT NULL,
    `bairro_id` INT UNSIGNED NOT NULL,
    `tipo` VARCHAR(40) NOT NULL,
    `nome` VARCHAR(180) NOT NULL,
    `diretor` VARCHAR(180) NULL,
    `endereco` VARCHAR(255) NULL,
    `panfletagem` TINYINT(1) NOT NULL DEFAULT 0,
    `panfletagem_atualizado_em` DATETIME NULL,
    `panfletagem_usuario_id` INT UNSIGNED NULL,
    `total_alunos` INT UNSIGNED NOT NULL DEFAULT 0,
    `indicadores` JSON NULL,
    `obs` TEXT NULL,
    `versao_row` INT UNSIGNED NOT NULL DEFAULT 1,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_escolas_cidade_bairro` (`cidade_id`, `bairro_id`),
    KEY `idx_escolas_panfletagem` (`panfletagem`, `panfletagem_atualizado_em`),
    KEY `idx_escolas_tipo` (`tipo`),
    FULLTEXT KEY `ft_escolas_search` (`nome`, `diretor`, `endereco`),
    CONSTRAINT `fk_escolas_cidades`
        FOREIGN KEY (`cidade_id`) REFERENCES `cidades` (`id`)
        ON DELETE RESTRICT,
    CONSTRAINT `fk_escolas_bairros`
        FOREIGN KEY (`bairro_id`) REFERENCES `bairros` (`id`)
        ON DELETE RESTRICT,
    CONSTRAINT `fk_escolas_usuarios`
        FOREIGN KEY (`panfletagem_usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cadastro de escolas';

CREATE TABLE IF NOT EXISTS `escola_periodos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `escola_id` INT UNSIGNED NOT NULL,
    `periodo` VARCHAR(40) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_escola_periodo` (`escola_id`, `periodo`),
    KEY `idx_escola_periodos_periodo` (`periodo`),
    CONSTRAINT `fk_escola_periodos_escolas`
        FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Turnos ofertados por escola';

CREATE TABLE IF NOT EXISTS `escola_etapas` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `escola_id` INT UNSIGNED NOT NULL,
    `etapa` VARCHAR(60) NOT NULL,
    `quantidade` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_escola_etapa` (`escola_id`, `etapa`),
    CONSTRAINT `fk_escola_etapas_escolas`
        FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Quantidade de alunos por etapa';

CREATE TABLE IF NOT EXISTS `escola_observacoes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `escola_id` INT UNSIGNED NOT NULL,
    `usuario_id` INT UNSIGNED NULL,
    `observacao` TEXT NOT NULL,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `removido_em` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `idx_escola_observacoes_escola` (`escola_id`),
    KEY `idx_escola_observacoes_usuario` (`usuario_id`),
    KEY `idx_escola_observacoes_removido` (`removido_em`),
    CONSTRAINT `fk_escola_observacoes_escolas`
        FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_escola_observacoes_usuarios`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Observacoes atuais das escolas';

CREATE TABLE IF NOT EXISTS `escola_observacao_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `escola_observacao_id` BIGINT UNSIGNED NOT NULL,
    `usuario_id` INT UNSIGNED NULL,
    `acao` ENUM('create', 'update', 'delete', 'restore') NOT NULL,
    `conteudo_antigo` JSON NULL,
    `conteudo_novo` JSON NULL,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_escola_observacao_logs_observacao` (`escola_observacao_id`),
    KEY `idx_escola_observacao_logs_usuario` (`usuario_id`),
    KEY `idx_escola_observacao_logs_acao` (`acao`),
    CONSTRAINT `fk_escola_observacao_logs_observacoes`
        FOREIGN KEY (`escola_observacao_id`) REFERENCES `escola_observacoes` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_escola_observacao_logs_usuarios`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historico de observacoes das escolas';

CREATE TABLE IF NOT EXISTS `escola_panfletagem_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `escola_id` INT UNSIGNED NOT NULL,
    `usuario_id` INT UNSIGNED NULL,
    `status_anterior` TINYINT(1) NOT NULL,
    `status_novo` TINYINT(1) NOT NULL,
    `observacao` TEXT NULL,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_escola_panfletagem_logs_escola` (`escola_id`),
    KEY `idx_escola_panfletagem_logs_usuario` (`usuario_id`),
    KEY `idx_escola_panfletagem_logs_status` (`status_novo`),
    KEY `idx_escola_panfletagem_logs_data` (`criado_em`),
    CONSTRAINT `fk_escola_panfletagem_logs_escolas`
        FOREIGN KEY (`escola_id`) REFERENCES `escolas` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_escola_panfletagem_logs_usuarios`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Auditoria de toggles de panfletagem';

CREATE TABLE IF NOT EXISTS `eventos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `titulo` VARCHAR(180) NOT NULL,
    `descricao` TEXT NULL,
    `cidade` VARCHAR(120) NULL,
    `local` VARCHAR(180) NULL,
    `inicio` DATETIME NOT NULL,
    `fim` DATETIME NOT NULL,
    `criado_por` INT UNSIGNED NULL,
    `atualizado_por` INT UNSIGNED NULL,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_eventos_inicio` (`inicio`),
    KEY `idx_eventos_fim` (`fim`),
    CONSTRAINT `fk_eventos_criado_por`
        FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `fk_eventos_atualizado_por`
        FOREIGN KEY (`atualizado_por`) REFERENCES `usuarios` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agenda de eventos para equipes de campo';

CREATE TABLE IF NOT EXISTS `evento_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `evento_id` BIGINT UNSIGNED NOT NULL,
    `usuario_id` INT UNSIGNED NULL,
    `acao` ENUM('create', 'update', 'delete', 'restore') NOT NULL,
    `payload_antigo` JSON NULL,
    `payload_novo` JSON NULL,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_evento_logs_evento` (`evento_id`),
    KEY `idx_evento_logs_usuario` (`usuario_id`),
    KEY `idx_evento_logs_acao` (`acao`),
    CONSTRAINT `fk_evento_logs_eventos`
        FOREIGN KEY (`evento_id`) REFERENCES `eventos` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_evento_logs_usuarios`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historico de alteracoes em eventos';

CREATE TABLE IF NOT EXISTS `sync_mutations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` VARCHAR(64) NOT NULL,
    `tipo` VARCHAR(60) NOT NULL,
    `payload` JSON NOT NULL,
    `versao_row` INT UNSIGNED NULL,
    `status` ENUM('pending', 'processing', 'applied', 'error') NOT NULL DEFAULT 'pending',
    `tentativas` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `erro` TEXT NULL,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `processado_em` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sync_mutations_status` (`status`),
    KEY `idx_sync_mutations_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Fila de mutacoes offline';

DROP VIEW IF EXISTS `v_escolas_panfletagem_recente`;
CREATE VIEW `v_escolas_panfletagem_recente` AS
SELECT
    l.escola_id,
    l.status_novo AS ultimo_status,
    l.usuario_id AS ultimo_usuario_id,
    l.criado_em AS ultimo_toggle_em
FROM escola_panfletagem_logs l
INNER JOIN (
    SELECT escola_id, MAX(criado_em) AS max_criado_em
    FROM escola_panfletagem_logs
    GROUP BY escola_id
) recents ON recents.escola_id = l.escola_id AND recents.max_criado_em = l.criado_em;
