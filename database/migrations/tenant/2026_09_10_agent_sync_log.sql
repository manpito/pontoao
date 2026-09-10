CREATE TABLE IF NOT EXISTS agent_sync_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    relogio_id  INT UNSIGNED NOT NULL,
    funcionario_id INT UNSIGNED NOT NULL,
    operacao    ENUM('insert','update','delete') NOT NULL,
    sucesso     TINYINT(1) NOT NULL DEFAULT 1,
    erro        TEXT NULL,
    timestamp   DATETIME NOT NULL,
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_funcionario (funcionario_id),
    INDEX idx_relogio (relogio_id),
    INDEX idx_timestamp (timestamp)
);
