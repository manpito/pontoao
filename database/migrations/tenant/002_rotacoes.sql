CREATE TABLE IF NOT EXISTS `rotacoes` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `horario_id`            INT UNSIGNED    NOT NULL,
    `nome`                  VARCHAR(100)    NOT NULL,
    `tipo`                  ENUM('ciclo_on_off','turno_rotativo','personalizado') NOT NULL DEFAULT 'ciclo_on_off',
    `dias_on`               SMALLINT        NULL,
    `dias_off`              SMALLINT        NULL,
    `data_inicio_ciclo`     DATE            NULL,
    `ignora_fds`            TINYINT(1)      NOT NULL DEFAULT 0,
    `ignora_feriados`       TINYINT(1)      NOT NULL DEFAULT 0,
    `dia_saida_antecipada`  TINYINT         NULL,
    `hora_saida_antecipada` TIME            NULL,
    `activo`                TINYINT(1)      NOT NULL DEFAULT 1,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`horario_id`) REFERENCES `horarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `rotacao_fases` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `rotacao_id`            INT UNSIGNED    NOT NULL,
    `ordem`                 TINYINT         NOT NULL,
    `nome`                  VARCHAR(60)     NOT NULL,
    `tipo_fase`             ENUM('trabalho','folga') NOT NULL DEFAULT 'trabalho',
    `duracao_dias`          SMALLINT        NOT NULL DEFAULT 1,
    `hora_entrada`          TIME            NULL,
    `hora_saida`            TIME            NULL,
    `hora_inicio_intervalo` TIME            NULL,
    `hora_fim_intervalo`    TIME            NULL,
    `horas_dia`             DECIMAL(4,2)    NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`rotacao_id`) REFERENCES `rotacoes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `funcionario_rotacao` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `funcionario_id`        INT UNSIGNED    NOT NULL,
    `rotacao_id`            INT UNSIGNED    NOT NULL,
    `data_inicio`           DATE            NOT NULL,
    `data_fim`              DATE            NULL,
    `fase_inicial`          TINYINT         NOT NULL DEFAULT 1,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`rotacao_id`) REFERENCES `rotacoes`(`id`)
) ENGINE=InnoDB;
