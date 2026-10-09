-- Migration: 015_add_folga_enum
-- Descrição: Acrescenta 'folga' ao ENUM comportamento da tabela tipos_justificacao

ALTER TABLE tipos_justificacao MODIFY COLUMN comportamento ENUM('trabalho', 'falta_justificada_remunerada', 'falta_justificada_nao_remunerada', 'folga') NOT NULL;
