CREATE TABLE anos_laborais (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ano YEAR NOT NULL,
    estado ENUM('pendente', 'activo', 'fechado') NOT NULL DEFAULT 'pendente',
    dia_inicio_semana TINYINT UNSIGNED NOT NULL DEFAULT 1,
    dia_fim_semana TINYINT UNSIGNED NOT NULL DEFAULT 5,
    data_inicio_periodo DATE NOT NULL COMMENT 'Dia do mês em que começa o período (ex: 21)',
    data_fim_periodo DATE NOT NULL COMMENT 'Dia do mês em que termina o período (ex: 20)',
    activado_em DATETIME NULL,
    activado_por INT UNSIGNED NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ano (ano)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
