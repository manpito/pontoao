-- Migration: 003_marcacoes_em_falta
-- Descrição: Cria a tabela marcacoes_em_falta para o sistema de gestão de excepções

CREATE TABLE marcacoes_em_falta (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    funcionario_id INT UNSIGNED NOT NULL,
    data DATE NOT NULL,
    marcacao_entrada_id INT UNSIGNED NULL,
    estado ENUM(
        'pendente',
        'justificada_trabalho',
        'justificada_motivo',
        'injustificada_meio_dia',
        'injustificada_falta'
    ) NOT NULL DEFAULT 'pendente',
    classificado_por INT UNSIGNED NULL,
    data_classificacao DATETIME NULL,
    nota_classificacao TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_funcionario_data (funcionario_id, data),
    INDEX idx_estado_data (estado, data),
    INDEX idx_funcionario (funcionario_id),
    CONSTRAINT fk_mef_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_mef_marcacao FOREIGN KEY (marcacao_entrada_id) REFERENCES marcacoes(id) ON DELETE SET NULL,
    CONSTRAINT fk_mef_classificador FOREIGN KEY (classificado_por) REFERENCES utilizadores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rollback:
-- DROP TABLE marcacoes_em_falta;
