-- Adicionar configurações SMTP à tabela configuracoes
INSERT IGNORE INTO `configuracoes` (`chave`, `valor`, `tipo`, `descricao`) VALUES
('smtp_host',        NULL, 'string',  'Servidor SMTP para envio de emails'),
('smtp_port',        '587', 'integer', 'Porta SMTP (geralmente 587 para TLS, 465 para SSL)'),
('smtp_user',        NULL, 'string',  'Utilizador SMTP (email de autenticação)'),
('smtp_pass',        NULL, 'string',  'Password SMTP'),
('smtp_from',        NULL, 'string',  'Email remetente'),
('smtp_from_name',   NULL, 'string',  'Nome do remetente'),
('smtp_encryption',  'tls', 'string', 'Tipo de encriptação: tls, ssl ou vazio');
