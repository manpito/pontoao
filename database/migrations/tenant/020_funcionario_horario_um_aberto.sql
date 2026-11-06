ALTER TABLE funcionario_horario ADD COLUMN aberto TINYINT GENERATED ALWAYS AS (IF(data_fim IS NULL, 1, NULL)) STORED, ADD UNIQUE KEY uq_funcionario_horario_aberto (funcionario_id, aberto);
