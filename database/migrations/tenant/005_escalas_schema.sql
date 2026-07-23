-- ============================================================
-- Tabela: turnos
-- Template reutilizável de horário concreto
-- ============================================================
CREATE TABLE turnos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    tipo ENUM('trabalho', 'folga', 'compensatorio') NOT NULL DEFAULT 'trabalho',
    hora_entrada TIME NULL COMMENT 'NULL se tipo=folga',
    hora_saida TIME NULL COMMENT 'NULL se tipo=folga',
    hora_inicio_intervalo TIME NULL,
    hora_fim_intervalo TIME NULL,
    horas_efectivas DECIMAL(4,2) NULL COMMENT 'Horas trabalhadas líquidas, sem intervalo',
    atravessa_dia_civil TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 se hora_saida < hora_entrada (turno nocturno)',
    classificacao_legal ENUM('diurno', 'nocturno', 'misto', 'nao_aplicavel') NOT NULL DEFAULT 'nao_aplicavel'
        COMMENT 'nocturno: pelo menos 3h entre 20h-06h (LGT Angola art 110)',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nome (nome),
    INDEX idx_tipo_activo (tipo, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabela: escalas
-- Padrão de escala: sequência ordenada de turnos que se repete
-- ============================================================
CREATE TABLE escalas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT NULL,
    departamento_id INT UNSIGNED NULL COMMENT 'Opcional: associa escala a departamento',
    tamanho_ciclo TINYINT UNSIGNED NOT NULL COMMENT 'Número total de posições/dias no ciclo (ex: 7 para 5/2, 5 para call center 5x5)',
    regime ENUM('normal','turnos') NOT NULL DEFAULT 'normal',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nome (nome),
    INDEX idx_departamento (departamento_id),
    CONSTRAINT fk_escala_departamento FOREIGN KEY (departamento_id) REFERENCES departamentos(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabela: escala_turnos
-- Sequência de turnos dentro de uma escala (ordem importa)
-- ============================================================
CREATE TABLE escala_turnos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    escala_id INT UNSIGNED NOT NULL,
    posicao TINYINT UNSIGNED NOT NULL COMMENT 'Posição no ciclo (1-indexed). Range: 1 .. escalas.tamanho_ciclo',
    turno_id INT UNSIGNED NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_escala_posicao (escala_id, posicao),
    INDEX idx_turno (turno_id),
    CONSTRAINT fk_et_escala FOREIGN KEY (escala_id) REFERENCES escalas(id) ON DELETE CASCADE,
    CONSTRAINT fk_et_turno FOREIGN KEY (turno_id) REFERENCES turnos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabela: funcionario_escala
-- Atribuição de funcionário a escala com posição inicial
-- ============================================================
CREATE TABLE funcionario_escala (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    funcionario_id INT UNSIGNED NOT NULL,
    escala_id INT UNSIGNED NOT NULL,
    data_inicio DATE NOT NULL COMMENT 'Primeiro dia em que o funcionário ocupa posicao_inicial',
    data_fim DATE NULL COMMENT 'Último dia (NULL = ainda activo)',
    posicao_inicial TINYINT UNSIGNED NOT NULL COMMENT 'Posição que o funcionário ocupa em data_inicio',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_funcionario (funcionario_id),
    INDEX idx_escala (escala_id),
    INDEX idx_datas (data_inicio, data_fim),
    CONSTRAINT fk_fe_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_fe_escala FOREIGN KEY (escala_id) REFERENCES escalas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tabela: escala_excepcoes
-- Substituições e cobertura manual de turnos
-- ============================================================
CREATE TABLE escala_excepcoes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('substituicao','atribuicao_directa') NOT NULL DEFAULT 'substituicao',
    data DATE NOT NULL,
    funcionario_ausente_id INT UNSIGNED NULL COMMENT 'Quem devia trabalhar mas não vai',
    funcionario_substituto_id INT UNSIGNED NULL COMMENT 'Quem cobre (NULL = turno descoberto)',
    turno_id INT UNSIGNED NOT NULL COMMENT 'O turno a ser coberto',
    motivo ENUM('ferias', 'doenca', 'pessoal', 'troca', 'outro') NOT NULL,
    descricao TEXT NULL,
    criado_por INT UNSIGNED NOT NULL COMMENT 'Utilizador que registou (supervisor/RH)',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_data (data),
    INDEX idx_ausente (funcionario_ausente_id, data),
    INDEX idx_substituto (funcionario_substituto_id, data),
    CONSTRAINT fk_exc_ausente FOREIGN KEY (funcionario_ausente_id) REFERENCES funcionarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_exc_substituto FOREIGN KEY (funcionario_substituto_id) REFERENCES funcionarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_exc_turno FOREIGN KEY (turno_id) REFERENCES turnos(id) ON DELETE RESTRICT,
    CONSTRAINT fk_exc_criado FOREIGN KEY (criado_por) REFERENCES utilizadores(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback:
-- DROP TABLE IF EXISTS escala_excepcoes;
-- DROP TABLE IF EXISTS funcionario_escala;
-- DROP TABLE IF EXISTS escala_turnos;
-- DROP TABLE IF EXISTS escalas;
-- DROP TABLE IF EXISTS turnos;

