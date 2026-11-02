SET NAMES utf8mb4;
SET time_zone = '+01:00';

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'fundamento') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `fundamento` ENUM(''a'',''b'',''c'',''d'',''e'',''f'',''g'') NULL AFTER `motivo`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'hora_inicio') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `hora_inicio` TIME NULL AFTER `fundamento`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'hora_fim') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `hora_fim` TIME NULL AFTER `hora_inicio`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'igt_autorizacao_ref') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `igt_autorizacao_ref` VARCHAR(100) NULL AFTER `hora_fim`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos_horas_extra' AND COLUMN_NAME = 'consentimento_trabalhador') = 0,
    'ALTER TABLE `pedidos_horas_extra` ADD COLUMN `consentimento_trabalhador` TINYINT(1) NOT NULL DEFAULT 0 AFTER `igt_autorizacao_ref`',
    'DO 0'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
