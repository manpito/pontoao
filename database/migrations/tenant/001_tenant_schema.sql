
SET NAMES utf8mb4;
SET time_zone = '+01:00';

CREATE TABLE `utilizadores` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `uuid`                  CHAR(36)        NOT NULL UNIQUE,
    `nome`                  VARCHAR(120)    NOT NULL,
    `email`                 VARCHAR(150)    NOT NULL UNIQUE,
    `password_hash`         VARCHAR(255)    NOT NULL,
    `perfil`                ENUM('super_admin_tenant','rh_manager','rh_colaborador','supervisor','funcionario') NOT NULL DEFAULT 'funcionario',
    `totp_secret`           VARCHAR(64)     NULL,
    `totp_activo`           TINYINT(1)      NOT NULL DEFAULT 0,
    `funcionario_id`        INT UNSIGNED    NULL COMMENT 'FK para tabela funcionarios (pode ser NULL para admins externos)',
    `ultimo_login`          DATETIME        NULL,
    `ultimo_ip`             VARCHAR(45)     NULL,
    `tentativas_login`      TINYINT         NOT NULL DEFAULT 0,
    `bloqueado_ate`         DATETIME        NULL,
    `activo`                TINYINT(1)      NOT NULL DEFAULT 1,
    `deve_alterar_password` TINYINT(1)      NOT NULL DEFAULT 1,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_em`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_perfil` (`perfil`)
) ENGINE=InnoDB;

CREATE TABLE `utilizador_tokens` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `utilizador_id`         INT UNSIGNED    NOT NULL,
    `token_hash`            VARCHAR(255)    NOT NULL UNIQUE,
    `ip`                    VARCHAR(45)     NULL,
    `user_agent`            TEXT            NULL,
    `expira_em`             DATETIME        NOT NULL,
    `revogado`              TINYINT(1)      NOT NULL DEFAULT 0,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`utilizador_id`) REFERENCES `utilizadores`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `departamentos` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nome`                  VARCHAR(100)    NOT NULL,
    `codigo`                VARCHAR(20)     NULL,
    `departamento_pai_id`   INT UNSIGNED    NULL COMMENT 'Para hierarquias',
    `responsavel_id`        INT UNSIGNED    NULL COMMENT 'FK utilizadores',
    `activo`                TINYINT(1)      NOT NULL DEFAULT 1,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`departamento_pai_id`) REFERENCES `departamentos`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `cargos` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nome`                  VARCHAR(100)    NOT NULL,
    `categoria_profissional` VARCHAR(80)    NULL,
    `departamento_id`       INT UNSIGNED    NULL,
    `activo`                TINYINT(1)      NOT NULL DEFAULT 1,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`departamento_id`) REFERENCES `departamentos`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `funcionarios` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `numero_funcionario`    VARCHAR(20)     NOT NULL UNIQUE COMMENT 'Gerado automaticamente, sequencial',
    `uuid`                  CHAR(36)        NOT NULL UNIQUE,

    `nome_completo`         VARCHAR(150)    NOT NULL,
    `data_nascimento`       DATE            NULL,
    `genero`                ENUM('M','F','Outro') NULL,
    `nacionalidade`         VARCHAR(60)     NULL DEFAULT 'Angolana',
    `nif`                   VARCHAR(20)     NULL COMMENT 'Número de Identificação Fiscal',
    `niss`                  VARCHAR(20)     NULL COMMENT 'Número INSS',
    `bi_numero`             VARCHAR(30)     NULL COMMENT 'Número do Bilhete de Identidade ou Passaporte',
    `bi_validade`           DATE            NULL,
    `provincia_naturalidade` VARCHAR(60)    NULL,
    `estado_civil`          ENUM('solteiro','casado','divorciado','viuvo','uniao_facto') NULL,
    `num_dependentes`       TINYINT         NOT NULL DEFAULT 0 COMMENT 'Para cálculo IRT',

    `morada`                TEXT            NULL,
    `municipio`             VARCHAR(80)     NULL,
    `provincia`             VARCHAR(60)     NULL,
    `telefone`              VARCHAR(20)     NULL,
    `telefone_alternativo`  VARCHAR(20)     NULL,
    `email`                 VARCHAR(150)    NULL,

    `departamento_id`       INT UNSIGNED    NULL,
    `cargo_id`              INT UNSIGNED    NULL,
    `supervisor_id`         INT UNSIGNED    NULL COMMENT 'FK para outro funcionário',
    `data_admissao`         DATE            NOT NULL,
    `tipo_contrato`         ENUM('prazo_determinado','prazo_indeterminado','prestacao_servicos') NOT NULL DEFAULT 'prazo_indeterminado',
    `data_fim_contrato`     DATE            NULL,
    `vencimento_base_aoa`   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `centro_custo`          VARCHAR(60)     NULL,
    `pin_marcacao`          VARCHAR(6)      NULL COMMENT 'PIN para marcação de ponto (hash bcrypt)',

    `estado`                ENUM('activo','suspenso','desligado') NOT NULL DEFAULT 'activo',
    `data_desligamento`     DATE            NULL,
    `motivo_desligamento`   TEXT            NULL,

    `foto_url`              VARCHAR(255)    NULL,
    `contrato_pdf_url`      VARCHAR(255)    NULL,

    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `actualizado_em`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_estado` (`estado`),
    INDEX `idx_departamento` (`departamento_id`),
    FOREIGN KEY (`departamento_id`) REFERENCES `departamentos`(`id`),
    FOREIGN KEY (`cargo_id`) REFERENCES `cargos`(`id`),
    FOREIGN KEY (`supervisor_id`) REFERENCES `funcionarios`(`id`)
) ENGINE=InnoDB COMMENT='Ficha completa do funcionário - LGT Lei 7/15';

ALTER TABLE `utilizadores` ADD CONSTRAINT `fk_util_func`
    FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios`(`id`) ON DELETE SET NULL;

CREATE TABLE `horarios` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nome`                  VARCHAR(100)    NOT NULL,
    `tipo`                  ENUM('normal','turnos','nocturno','flexivel') NOT NULL DEFAULT 'normal',
    `horas_dia`             DECIMAL(4,2)    NOT NULL DEFAULT 8.00,
    `horas_semana`          DECIMAL(5,2)    NOT NULL DEFAULT 44.00,
    `tolerancia_entrada_min` SMALLINT       NOT NULL DEFAULT 10 COMMENT 'Minutos de tolerância na entrada',
    `intervalo_min`         SMALLINT        NOT NULL DEFAULT 60 COMMENT 'Duração do intervalo em minutos',
    `activo`                TINYINT(1)      NOT NULL DEFAULT 1,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB COMMENT='Tipos de horário - Art. 96.º e 100.º LGT';

CREATE TABLE `horario_turnos` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `horario_id`            INT UNSIGNED    NOT NULL,
    `dia_semana`            TINYINT         NOT NULL COMMENT '0=Dom, 1=Seg, ..., 6=Sab',
    `hora_entrada`          TIME            NOT NULL,
    `hora_saida`            TIME            NOT NULL,
    `hora_inicio_intervalo` TIME            NULL,
    `hora_fim_intervalo`    TIME            NULL,
    `dia_folga`             TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`horario_id`) REFERENCES `horarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `funcionario_horario` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `funcionario_id`        INT UNSIGNED    NOT NULL,
    `horario_id`            INT UNSIGNED    NOT NULL,
    `data_inicio`           DATE            NOT NULL,
    `data_fim`              DATE            NULL COMMENT 'NULL = em vigor',
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`horario_id`) REFERENCES `horarios`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `feriados` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nome`                  VARCHAR(120)    NOT NULL,
    `data`                  DATE            NOT NULL,
    `tipo`                  ENUM('nacional','provincial','empresa') NOT NULL DEFAULT 'nacional',
    `meio_dia`              TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = feriado de meio-dia (ex: 8 Março)',
    `recorrente`            TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1 = repete todos os anos na mesma data',
    `ano`                   YEAR            NULL COMMENT 'NULL se recorrente',
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_feriado_data_tipo` (`data`, `tipo`)
) ENGINE=InnoDB COMMENT='Feriados angolanos - Art. 225.º LGT';

CREATE TABLE `relogios` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nome`                  VARCHAR(80)     NOT NULL,
    `localizacao`           VARCHAR(120)    NULL COMMENT 'ex: Entrada Principal, Armazém',
    `ip`                    VARCHAR(45)     NOT NULL,
    `porta`                 SMALLINT UNSIGNED NOT NULL DEFAULT 4370,
    `device_id`             VARCHAR(50)     NULL,
    `modelo`                ENUM('zkteco','facepro','outro') NOT NULL DEFAULT 'zkteco',
    `api_key_hash`          VARCHAR(255)    NOT NULL COMMENT 'Hash da API key para autenticação com zk-bridge',
    `ultimo_heartbeat`      DATETIME        NULL,
    `ultimo_sync`           DATETIME        NULL,
    `activo`                TINYINT(1)      NOT NULL DEFAULT 1,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB COMMENT='Dispositivos físicos de marcação de ponto';

CREATE TABLE `marcacoes` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `funcionario_id`        INT UNSIGNED    NOT NULL,
    `tipo`                  ENUM('entrada','saida','inicio_intervalo','fim_intervalo','saida_servico','regresso_servico') NOT NULL,
    `data_hora`             DATETIME        NOT NULL,
    `data_hora_original`    DATETIME        NOT NULL COMMENT 'Valor original imutável',
    `origem`                ENUM('relogio','web_pin','web_qr','web_face','manual') NOT NULL,
    `relogio_id`            INT UNSIGNED    NULL,
    `ip_marcacao`           VARCHAR(45)     NULL,
    `user_agent`            TEXT            NULL,
    `latitude`              DECIMAL(10,8)   NULL,
    `longitude`             DECIMAL(11,8)   NULL,
    `dentro_geofence`       TINYINT(1)      NULL,
    `editada`               TINYINT(1)      NOT NULL DEFAULT 0,
    `editada_por`           INT UNSIGNED    NULL COMMENT 'FK utilizadores',
    `motivo_edicao`         TEXT            NULL,
    `data_edicao`           DATETIME        NULL,
    `bloqueada`             TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = mês fechado, imutável',
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_funcionario_data` (`funcionario_id`, `data_hora`),
    INDEX `idx_data` (`data_hora`),
    FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios`(`id`),
    FOREIGN KEY (`relogio_id`) REFERENCES `relogios`(`id`),
    FOREIGN KEY (`editada_por`) REFERENCES `utilizadores`(`id`)
) ENGINE=InnoDB COMMENT='Registo imutável de marcações - auditável MAPTSS';

CREATE TABLE `anomalias` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `funcionario_id`        INT UNSIGNED    NOT NULL,
    `data`                  DATE            NOT NULL,
    `tipo`                  ENUM('atraso','saida_antecipada','marcacao_unica','intervalo_excessivo','ausencia','fora_geofence') NOT NULL,
    `minutos`               SMALLINT        NULL COMMENT 'Minutos de atraso, antecipação ou excesso',
    `resolvida`             TINYINT(1)      NOT NULL DEFAULT 0,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_func_data` (`funcionario_id`, `data`),
    FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `justificacoes` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `funcionario_id`        INT UNSIGNED    NOT NULL,
    `data_inicio`           DATE            NOT NULL,
    `data_fim`              DATE            NOT NULL,
    `tipo`                  ENUM('falta_justificada','falta_injustificada','licenca_maternidade','licenca_paternidade','licenca_doenca','formacao','servico_militar','luto','outro') NOT NULL,
    `descricao`             TEXT            NULL,
    `documento_url`         VARCHAR(255)    NULL COMMENT 'Upload de atestado, declaração, etc.',
    `estado`                ENUM('pendente','aprovada','rejeitada') NOT NULL DEFAULT 'pendente',
    `aprovada_por`          INT UNSIGNED    NULL,
    `data_aprovacao`        DATETIME        NULL,
    `obs_aprovacao`         TEXT            NULL,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios`(`id`),
    FOREIGN KEY (`aprovada_por`) REFERENCES `utilizadores`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `ferias` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `funcionario_id`        INT UNSIGNED    NOT NULL,
    `ano`                   YEAR            NOT NULL,
    `dias_direito`          TINYINT         NOT NULL DEFAULT 22 COMMENT 'Mínimo legal: 22 dias úteis - Art. 215.º LGT',
    `dias_gozados`          TINYINT         NOT NULL DEFAULT 0,
    `dias_pendentes`        TINYINT         NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_func_ano` (`funcionario_id`, `ano`),
    FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios`(`id`)
) ENGINE=InnoDB COMMENT='Saldo de férias - Art. 215.º LGT';

CREATE TABLE `ferias_pedidos` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `funcionario_id`        INT UNSIGNED    NOT NULL,
    `ano`                   YEAR            NULL,
    `data_inicio`           DATE            NOT NULL,
    `data_fim`              DATE            NOT NULL,
    `dias_uteis`            TINYINT         NOT NULL,
    `estado`                ENUM('pendente','aprovado_supervisor','aprovado_rh','rejeitado','cancelado') NOT NULL DEFAULT 'pendente',
    `aprovado_supervisor_por` INT UNSIGNED  NULL,
    `aprovado_rh_por`       INT UNSIGNED    NULL,
    `data_aprovacao_final`  DATETIME        NULL,
    `motivo_rejeicao`       TEXT            NULL,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `log_auditoria` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `utilizador_id`         INT UNSIGNED    NULL,
    `accao`                 VARCHAR(100)    NOT NULL,
    `entidade`              VARCHAR(60)     NULL COMMENT 'ex: funcionario, marcacao, utilizador',
    `entidade_id`           INT UNSIGNED    NULL,
    `dados_antes`           JSON            NULL,
    `dados_depois`          JSON            NULL,
    `ip`                    VARCHAR(45)     NULL,
    `user_agent`            TEXT            NULL,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_entidade` (`entidade`, `entidade_id`),
    INDEX `idx_criado` (`criado_em`)
) ENGINE=InnoDB COMMENT='Auditoria imutável para fiscalização MAPTSS';

CREATE TABLE `periodos_mensais` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `ano`                   YEAR            NOT NULL,
    `mes`                   TINYINT         NOT NULL COMMENT '1-12',
    `estado`                ENUM('aberto','em_revisao','fechado') NOT NULL DEFAULT 'aberto',
    `fechado_por`           INT UNSIGNED    NULL,
    `data_fecho`            DATETIME        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ano_mes` (`ano`, `mes`),
    FOREIGN KEY (`fechado_por`) REFERENCES `utilizadores`(`id`)
) ENGINE=InnoDB COMMENT='Controlo de fecho mensal - bloqueia edição de marcações';

CREATE TABLE `notificacoes` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `utilizador_id`         INT UNSIGNED    NOT NULL,
    `tipo`                  VARCHAR(60)     NOT NULL,
    `titulo`                VARCHAR(200)    NOT NULL,
    `mensagem`              TEXT            NOT NULL,
    `lida`                  TINYINT(1)      NOT NULL DEFAULT 0,
    `criado_em`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_util_lida` (`utilizador_id`, `lida`),
    FOREIGN KEY (`utilizador_id`) REFERENCES `utilizadores`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `configuracoes` (
    `chave`                 VARCHAR(80)     NOT NULL,
    `valor`                 TEXT            NULL,
    `tipo`                  ENUM('string','integer','boolean','json') NOT NULL DEFAULT 'string',
    `descricao`             VARCHAR(255)    NULL,
    PRIMARY KEY (`chave`)
) ENGINE=InnoDB;

INSERT INTO `configuracoes` (`chave`, `valor`, `tipo`, `descricao`) VALUES
('geofencing_activo',       '0',                'boolean', 'Activar validação de GPS nas marcações web'),
('geofencing_raio_metros',  '200',              'integer', 'Raio em metros para validação de geofencing'),
('geofencing_lat',          NULL,               'string',  'Latitude do ponto central do escritório'),
('geofencing_lng',          NULL,               'string',  'Longitude do ponto central do escritório'),
('tolerancia_entrada_min',  '10',               'integer', 'Minutos de tolerância de entrada por defeito'),
('fecho_mensal_dia',        '5',                'integer', 'Dia do mês seguinte para fecho automático'),
('notif_ausencia_horas',    '2',                'integer', 'Horas sem marcação para disparar alerta'),
('timezone',                'Africa/Luanda',    'string',  'Fuso horário'),
('moeda',                   'AOA',              'string',  'Moeda (Kwanza Angolano)'),
('logo_empresa',            NULL,               'string',  'URL do logótipo da empresa'),
('horas_extra_entrada_antecipada', '0', 'boolean', 'Contar tempo de entrada antecipada como horas extra');

ALTER TABLE `periodos_mensais` ADD COLUMN `data_inicio` DATE NULL AFTER `mes`;
ALTER TABLE `periodos_mensais` ADD COLUMN `data_fim` DATE NULL AFTER `data_inicio`;
ALTER TABLE `periodos_mensais` ADD COLUMN `fechado_em` DATETIME NULL AFTER `data_fecho`;


-- Tabela: pedidos_horas_extra
CREATE TABLE IF NOT EXISTS pedidos_horas_extra (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    funcionario_id  INT UNSIGNED NOT NULL,
    data            DATE NOT NULL,
    minutos         SMALLINT UNSIGNED NOT NULL,
    tipo            ENUM('normal','excepcional') NOT NULL DEFAULT 'normal',
    motivo          TEXT NOT NULL,
    estado          ENUM('pendente','aprovado_rh','aprovado','rejeitado') NOT NULL DEFAULT 'pendente',
    rejeitado_motivo TEXT NULL,
    submetido_por   INT UNSIGNED NULL,
    aprovado_rh_por INT UNSIGNED NULL,
    aprovado_por    INT UNSIGNED NULL,
    data_pedido     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_aprovacao  DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_funcionario_data (funcionario_id, data),
    KEY idx_estado (estado),
    CONSTRAINT fk_phe_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE
);

