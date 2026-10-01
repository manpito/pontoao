CREATE TABLE `tipos_justificacao` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `codigo`                VARCHAR(50)     NOT NULL UNIQUE,
    `nome`                  VARCHAR(120)    NOT NULL,
    `comportamento`         ENUM('trabalho', 'falta_justificada') NOT NULL,
    `activo`                TINYINT(1)      NOT NULL DEFAULT 1,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `tipos_justificacao` (`codigo`, `nome`, `comportamento`) VALUES
('servico_externo', 'Serviço Externo / Viagem de Trabalho', 'trabalho'),
('falta_justificada', 'Falta Justificada', 'falta_justificada');

ALTER TABLE `justificacoes_ausencia` MODIFY `tipo` VARCHAR(50) NOT NULL;
