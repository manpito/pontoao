SET NAMES utf8mb4;
SET time_zone = '+01:00';

ALTER TABLE `tipos_justificacao` ADD COLUMN `cor` CHAR(7) NULL;

CREATE TABLE IF NOT EXISTS `ajustes_lotes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `criado_por` INT UNSIGNED NOT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `tipo_justificacao_id` INT UNSIGNED NOT NULL,
    `criterio` JSON NOT NULL,
    `datas` JSON NOT NULL,
    `nota` TEXT NULL,
    `total_funcionarios` INT UNSIGNED NOT NULL,
    `total_dias` INT UNSIGNED NOT NULL,
    `estado` ENUM('activo', 'anulado') NOT NULL DEFAULT 'activo',
    `anulado_por` INT UNSIGNED NULL,
    `anulado_em` DATETIME NULL,
    `motivo_anulacao` TEXT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_ajustes_lotes_tipo` FOREIGN KEY (`tipo_justificacao_id`) REFERENCES `tipos_justificacao`(`id`),
    CONSTRAINT `fk_ajustes_lotes_criado_por` FOREIGN KEY (`criado_por`) REFERENCES `utilizadores`(`id`),
    CONSTRAINT `fk_ajustes_lotes_anulado_por` FOREIGN KEY (`anulado_por`) REFERENCES `utilizadores`(`id`)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ajustes_dia` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `funcionario_id` INT UNSIGNED NOT NULL,
    `data` DATE NOT NULL,
    `tipo_justificacao_id` INT UNSIGNED NOT NULL,
    `nota` TEXT NULL,
    `lote_id` INT UNSIGNED NULL,
    `estado` ENUM('activo', 'anulado') NOT NULL DEFAULT 'activo',
    `activo` TINYINT(1) GENERATED ALWAYS AS (IF(`estado` = 'activo', 1, NULL)) VIRTUAL,
    `criado_por` INT UNSIGNED NOT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `anulado_por` INT UNSIGNED NULL,
    `anulado_em` DATETIME NULL,
    `motivo_anulacao` TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ajuste_dia_func_data_activo` (`funcionario_id`, `data`, `activo`),
    INDEX `idx_ajuste_dia_func_data` (`funcionario_id`, `data`),
    CONSTRAINT `fk_ajustes_dia_func` FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ajustes_dia_tipo` FOREIGN KEY (`tipo_justificacao_id`) REFERENCES `tipos_justificacao`(`id`),
    CONSTRAINT `fk_ajustes_dia_lote` FOREIGN KEY (`lote_id`) REFERENCES `ajustes_lotes`(`id`),
    CONSTRAINT `fk_ajustes_dia_criado_por` FOREIGN KEY (`criado_por`) REFERENCES `utilizadores`(`id`),
    CONSTRAINT `fk_ajustes_dia_anulado_por` FOREIGN KEY (`anulado_por`) REFERENCES `utilizadores`(`id`)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
