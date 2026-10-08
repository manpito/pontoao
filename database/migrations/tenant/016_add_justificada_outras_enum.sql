-- Migration: 016_add_justificada_outras_enum
-- Descrição: Acrescenta 'justificada_outras' ao ENUM estado da tabela marcacoes_em_falta

ALTER TABLE marcacoes_em_falta MODIFY COLUMN estado ENUM('pendente', 'justificada_trabalho', 'justificada_motivo', 'justificada_outras', 'injustificada_meio_dia', 'injustificada_falta') NOT NULL DEFAULT 'pendente';
