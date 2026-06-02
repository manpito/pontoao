-- Migration 007: Grupos de Segurança para Terminais
-- Adiciona suporte para restringir terminais por departamento

CREATE TABLE IF NOT EXISTS `relogio_departamentos` (
    `relogio_id`      INT UNSIGNED NOT NULL,
    `departamento_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`relogio_id`, `departamento_id`),
    FOREIGN KEY (`relogio_id`)      REFERENCES `relogios`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`departamento_id`) REFERENCES `departamentos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE `relogios`
ADD COLUMN `default_departamento_id` INT UNSIGNED NULL AFTER `modelo`,
ADD CONSTRAINT `fk_relogio_dep_default`
    FOREIGN KEY (`default_departamento_id`) REFERENCES `departamentos`(`id`) ON DELETE SET NULL;
