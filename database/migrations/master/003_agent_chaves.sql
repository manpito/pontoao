-- Chaves do agente PontoAO (sync PontoAO -> relógios Dahua), uma por instalação e tenant.
-- Só se guarda o sha256 da chave; a chave em claro existe apenas no config.ini do instalador.
CREATE TABLE IF NOT EXISTS `agent_chaves` (
    `id`                        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `tenant_id`                 INT UNSIGNED    NOT NULL,
    `chave_hash`                CHAR(64)        NOT NULL,
    `relogio_id`                INT UNSIGNED    NULL COMMENT 'Relógio a partir do qual o instalador foi descarregado',
    `criado_por_utilizador_id`  INT UNSIGNED    NULL COMMENT 'utilizadores.id na base do tenant',
    `criado_em`                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `revogada_em`               DATETIME        NULL,
    `ultimo_uso_em`             DATETIME        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_agent_chaves_hash` (`chave_hash`),
    KEY `idx_agent_chaves_tenant` (`tenant_id`, `revogada_em`),
    CONSTRAINT `fk_agent_chaves_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Chaves do agente PontoAO por tenant';