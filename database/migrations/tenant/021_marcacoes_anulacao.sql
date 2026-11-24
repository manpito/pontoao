ALTER TABLE marcacoes
  ADD COLUMN anulada TINYINT(1) NOT NULL DEFAULT 0 AFTER bloqueada,
  ADD COLUMN anulada_por INT UNSIGNED NULL AFTER anulada,
  ADD COLUMN anulada_em DATETIME NULL AFTER anulada_por,
  ADD COLUMN motivo_anulacao VARCHAR(500) NULL AFTER anulada_em;
