CREATE TABLE `justificacoes_ausencia` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `funcionario_id`        INT UNSIGNED    NOT NULL,
    `data_inicio`           DATE            NOT NULL,
    `data_fim`              DATE            NOT NULL,
    `tipo`                  ENUM('servico_externo', 'falta_justificada') NOT NULL,
    `motivo`                ENUM('saude', 'luto', 'casamento', 'assistencia_familiar', 'outro') NULL,
    `nota`                  TEXT            NULL,
    `documento_url`         VARCHAR(255)    NULL,
    `estado`                ENUM('pendente', 'aprovado', 'rejeitado') NOT NULL DEFAULT 'pendente',
    `criado_por`            INT UNSIGNED    NOT NULL,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `aprovado_por`          INT UNSIGNED    NULL,
    `aprovado_em`           DATETIME        NULL,
    `motivo_rejeicao`       TEXT            NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
