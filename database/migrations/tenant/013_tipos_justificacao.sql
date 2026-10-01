CREATE TABLE IF NOT EXISTS tipos_justificacao (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(50) NOT NULL UNIQUE,
  nome VARCHAR(100) NOT NULL,
  comportamento ENUM('trabalho', 'falta_justificada_remunerada', 'falta_justificada_nao_remunerada') NOT NULL,
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4;

INSERT IGNORE INTO tipos_justificacao (codigo, nome, comportamento) VALUES
  ('servico_externo', 'Serviço Externo / Viagem de Trabalho', 'trabalho'),
  ('falta_justificada', 'Falta Justificada', 'falta_justificada_remunerada');
