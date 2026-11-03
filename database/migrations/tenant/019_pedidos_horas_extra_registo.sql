SET NAMES utf8mb4;
SET time_zone = '+01:00';

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'inicio_real') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `inicio_real` DATETIME NULL AFTER `consentimento_trabalhador`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'fim_real') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `fim_real` DATETIME NULL AFTER `inicio_real`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'minutos_reais') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `minutos_reais` SMALLINT UNSIGNED NULL AFTER `fim_real`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'registo_justificacao') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `registo_justificacao` TEXT NULL AFTER `minutos_reais`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'registado_por') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `registado_por` INT UNSIGNED NULL AFTER `registo_justificacao`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'registado_em') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `registado_em` DATETIME NULL AFTER `registado_por`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'visto_em') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `visto_em` DATETIME NULL AFTER `registado_em`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'visto_por') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `visto_por` INT UNSIGNED NULL AFTER `visto_em`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
