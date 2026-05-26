-- ============================================================
-- SCHEMA: saas_master
-- Base de dados central do SaaS
-- Contém: tenants, planos, facturação, super-admins, logs
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+01:00'; -- Africa/Luanda UTC+1

CREATE DATABASE IF NOT EXISTS `saas_master`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `saas_master`;

-- ------------------------------------------------------------
-- Planos de subscrição
-- ------------------------------------------------------------
CREATE TABLE `planos` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nome`                  VARCHAR(60)     NOT NULL,
    `slug`                  VARCHAR(60)     NOT NULL UNIQUE,
    `max_funcionarios`      SMALLINT        NOT NULL DEFAULT 50,
    `max_relogios`          TINYINT         NOT NULL DEFAULT 2,
    `max_departamentos`     SMALLINT        NOT NULL DEFAULT 10,
    `preco_mensal_aoa`      DECIMAL(12,2)   NOT NULL DEFAULT 0.00 COMMENT 'Preço em Kwanza (AOA)',
    `preco_anual_aoa`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `permite_face_recog`    TINYINT(1)      NOT NULL DEFAULT 0,
    `permite_api`           TINYINT(1)      NOT NULL DEFAULT 0,
    `permite_geofencing`    TINYINT(1)      NOT NULL DEFAULT 1,
    `permite_relat_avanc`   TINYINT(1)      NOT NULL DEFAULT 0,
    `retencao_dados_meses`  TINYINT         NOT NULL DEFAULT 24 COMMENT 'Meses de retenção de dados históricos',
    `activo`                TINYINT(1)      NOT NULL DEFAULT 1,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB COMMENT='Planos de subscrição SaaS';

-- ------------------------------------------------------------
-- Tenants (empresas clientes)
-- ------------------------------------------------------------
CREATE TABLE `tenants` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL UNIQUE COMMENT 'UUID público do tenant',
    `nome_empresa`          VARCHAR(150)    NOT NULL,
    `nif`                   VARCHAR(20)     NOT NULL COMMENT 'NIF da empresa',
    `email_contacto`        VARCHAR(150)    NOT NULL,
    `telefone`              VARCHAR(30)     NULL,
    `morada`                TEXT            NULL,
    `municipio`             VARCHAR(80)     NULL,
    `provincia`             VARCHAR(60)     NULL,
    `subdominio`            VARCHAR(60)     NOT NULL UNIQUE COMMENT 'ex: empresa1 → empresa1.plataforma.ao',
    `db_nome`               VARCHAR(80)     NOT NULL UNIQUE COMMENT 'Nome da base de dados MySQL do tenant',
    `db_host`               VARCHAR(120)    NOT NULL DEFAULT '127.0.0.1',
    `db_usuario`            VARCHAR(80)     NOT NULL,
    `db_password_enc`       TEXT            NOT NULL COMMENT 'Password encriptada com AES-256',
    `plano_id`              INT UNSIGNED    NOT NULL,
    `estado`                ENUM('activo','suspenso','cancelado','trial') NOT NULL DEFAULT 'trial',
    `data_inicio_plano`     DATE            NULL,
    `data_fim_plano`        DATE            NULL,
    `trial_ate`             DATE            NULL,
    `logo_url`              VARCHAR(255)    NULL,
    `fuso_horario`          VARCHAR(50)     NOT NULL DEFAULT 'Africa/Luanda',
    `schema_versao`         SMALLINT        NOT NULL DEFAULT 1 COMMENT 'Versão do schema aplicada ao tenant',
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_em`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_subdominio` (`subdominio`),
    INDEX `idx_estado` (`estado`),
    FOREIGN KEY (`plano_id`) REFERENCES `planos`(`id`)
) ENGINE=InnoDB COMMENT='Empresas clientes (tenants)';

-- ------------------------------------------------------------
-- Super-administradores (proprietários do SaaS)
-- ------------------------------------------------------------
CREATE TABLE `super_admins` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nome`                  VARCHAR(120)    NOT NULL,
    `email`                 VARCHAR(150)    NOT NULL UNIQUE,
    `password_hash`         VARCHAR(255)    NOT NULL COMMENT 'bcrypt custo 12',
    `totp_secret`           VARCHAR(64)     NULL COMMENT 'Segredo TOTP para 2FA',
    `totp_activo`           TINYINT(1)      NOT NULL DEFAULT 0,
    `ultimo_login`          DATETIME        NULL,
    `ultimo_ip`             VARCHAR(45)     NULL,
    `activo`                TINYINT(1)      NOT NULL DEFAULT 1,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB COMMENT='Administradores centrais do SaaS';

-- ------------------------------------------------------------
-- Refresh tokens de super-admins
-- ------------------------------------------------------------
CREATE TABLE `super_admin_tokens` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `super_admin_id`        INT UNSIGNED    NOT NULL,
    `token_hash`            VARCHAR(255)    NOT NULL UNIQUE,
    `ip`                    VARCHAR(45)     NULL,
    `user_agent`            TEXT            NULL,
    `expira_em`             DATETIME        NOT NULL,
    `revogado`              TINYINT(1)      NOT NULL DEFAULT 0,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`super_admin_id`) REFERENCES `super_admins`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Log de auditoria central (acções sobre tenants)
-- ------------------------------------------------------------
CREATE TABLE `log_auditoria_master` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `super_admin_id`        INT UNSIGNED    NULL,
    `tenant_id`             INT UNSIGNED    NULL,
    `accao`                 VARCHAR(100)    NOT NULL COMMENT 'ex: tenant.criado, tenant.suspenso, plano.alterado',
    `descricao`             TEXT            NULL,
    `dados_antes`           JSON            NULL,
    `dados_depois`          JSON            NULL,
    `ip`                    VARCHAR(45)     NULL,
    `user_agent`            TEXT            NULL,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_tenant` (`tenant_id`),
    INDEX `idx_accao` (`accao`),
    INDEX `idx_criado` (`criado_em`)
) ENGINE=InnoDB COMMENT='Auditoria central imutável - não criar UPDATE/DELETE nesta tabela';

-- ------------------------------------------------------------
-- Histórico de facturação
-- ------------------------------------------------------------
CREATE TABLE `faturas` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `tenant_id`             INT UNSIGNED    NOT NULL,
    `numero_fatura`         VARCHAR(30)     NOT NULL UNIQUE,
    `plano_id`              INT UNSIGNED    NOT NULL,
    `valor_aoa`             DECIMAL(12,2)   NOT NULL,
    `periodo_inicio`        DATE            NOT NULL,
    `periodo_fim`           DATE            NOT NULL,
    `estado`                ENUM('pendente','pago','cancelado','vencido') NOT NULL DEFAULT 'pendente',
    `data_pagamento`        DATE            NULL,
    `metodo_pagamento`      VARCHAR(60)     NULL,
    `referencia_pagamento`  VARCHAR(100)    NULL,
    `notas`                 TEXT            NULL,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`),
    FOREIGN KEY (`plano_id`) REFERENCES `planos`(`id`)
) ENGINE=InnoDB COMMENT='Facturação dos tenants em AOA (Kwanza)';

-- ------------------------------------------------------------
-- Migrations aplicadas por tenant (controlo de versão de schema)
-- ------------------------------------------------------------
CREATE TABLE `tenant_migrations` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `tenant_id`             INT UNSIGNED    NOT NULL,
    `migration`             VARCHAR(255)    NOT NULL,
    `aplicada_em`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_migration` (`tenant_id`, `migration`),
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Dados iniciais: Planos
-- ------------------------------------------------------------
INSERT INTO `planos` (`nome`, `slug`, `max_funcionarios`, `max_relogios`, `max_departamentos`, `preco_mensal_aoa`, `preco_anual_aoa`, `permite_face_recog`, `permite_api`, `permite_geofencing`, `permite_relat_avanc`, `retencao_dados_meses`) VALUES
('Básico',       'basico',       25,  1,  5,   25000.00,  270000.00, 0, 0, 0, 0, 12),
('Profissional', 'profissional', 100, 5,  20,  65000.00,  700000.00, 0, 1, 1, 1, 24),
('Enterprise',   'enterprise',   500, 20, 100, 150000.00, 1600000.00,1, 1, 1, 1, 60);
