-- Migration: 016_add_justificada_outras_enum
-- Descrição: Acrescenta 'justificada_outras' ao ENUM estado da tabela marcacoes_em_falta

SET @table_exists = (
    SELECT count(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'marcacoes_em_falta'
);

SET @sql_statement = IF(
    @table_exists > 0,
    "ALTER TABLE marcacoes_em_falta MODIFY COLUMN estado ENUM('pendente', 'justificada_trabalho', 'justificada_motivo', 'injustificada_meio_dia', 'injustificada_falta', 'justificada_outras') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente';",
    "DO 0;"
);

PREPARE stmt FROM @sql_statement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
