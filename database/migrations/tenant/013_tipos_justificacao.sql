CREATE TABLE tipos_justificacao (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(50) NOT NULL UNIQUE,
  nome VARCHAR(100) NOT NULL,
  comportamento ENUM('trabalho', 'falta_justificada') NOT NULL,
  activo BOOLEAN NOT NULL DEFAULT TRUE,
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO tipos_justificacao (codigo, nome, comportamento) VALUES
  ('servico_externo', 'Serviço Externo / Viagem de Trabalho', 'trabalho'),
  ('falta_justificada', 'Falta Justificada', 'falta_justificada');
