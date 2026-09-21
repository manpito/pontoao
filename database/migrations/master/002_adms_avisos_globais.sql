CREATE TABLE IF NOT EXISTS adms_avisos_globais (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('sn_desconhecido') NOT NULL,
    sn_relogio VARCHAR(100) NOT NULL,
    payload_bruto TEXT NULL,
    resolvido TINYINT(1) NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_resolvido (resolvido),
    INDEX idx_tipo (tipo)
) ENGINE=InnoDB;
