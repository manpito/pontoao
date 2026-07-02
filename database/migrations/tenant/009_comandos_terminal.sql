CREATE TABLE IF NOT EXISTS comandos_terminal (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    relogio_id INT UNSIGNED NOT NULL,
    tipo ENUM('criar_utilizador', 'apagar_utilizador', 'actualizar_utilizador') NOT NULL,
    payload TEXT NOT NULL COMMENT 'Comando ADMS completo a enviar ao relógio',
    estado ENUM('pendente', 'enviado', 'confirmado', 'erro') NOT NULL DEFAULT 'pendente',
    tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    funcionario_id INT UNSIGNED NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em DATETIME NULL,
    confirmado_em DATETIME NULL,
    erro_detalhe TEXT NULL,
    FOREIGN KEY (relogio_id) REFERENCES relogios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
