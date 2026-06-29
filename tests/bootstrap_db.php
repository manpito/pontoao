<?php

declare(strict_types=1);

/**
 * Inicializa uma base de dados SQLite em memória para testes.
 */

function bootstrap_db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Schema mínimo
    $pdo->exec("
        CREATE TABLE funcionarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            numero_funcionario TEXT,
            nome_completo TEXT,
            nome TEXT NOT NULL,
            departamento_id INTEGER,
            cargo_id INTEGER,
            horario_id INTEGER,
            data_admissao TEXT,
            estado TEXT DEFAULT 'activo'
        );

        CREATE TABLE departamentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL
        );

        CREATE TABLE utilizadores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            perfil TEXT
        );

        CREATE TABLE turnos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            tipo TEXT NOT NULL DEFAULT 'trabalho',
            hora_entrada TEXT,
            hora_saida TEXT,
            hora_inicio_intervalo TEXT,
            hora_fim_intervalo TEXT,
            horas_efectivas REAL,
            atravessa_dia_civil INTEGER NOT NULL DEFAULT 0,
            classificacao_legal TEXT NOT NULL DEFAULT 'nao_aplicavel',
            activo INTEGER NOT NULL DEFAULT 1
        );

        CREATE TABLE escalas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            descricao TEXT,
            departamento_id INTEGER,
            tamanho_ciclo INTEGER NOT NULL,
            regime TEXT NOT NULL DEFAULT 'turnos',
            activo INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY (departamento_id) REFERENCES departamentos(id)
        );

        CREATE TABLE escala_turnos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            escala_id INTEGER NOT NULL,
            posicao INTEGER NOT NULL,
            turno_id INTEGER NOT NULL,
            FOREIGN KEY (escala_id) REFERENCES escalas(id),
            FOREIGN KEY (turno_id) REFERENCES turnos(id)
        );

        CREATE TABLE funcionario_escala (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            funcionario_id INTEGER NOT NULL,
            escala_id INTEGER NOT NULL,
            data_inicio TEXT NOT NULL,
            data_fim TEXT,
            posicao_inicial INTEGER NOT NULL,
            FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id),
            FOREIGN KEY (escala_id) REFERENCES escalas(id)
        );

        CREATE TABLE escala_excepcoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tipo TEXT NOT NULL DEFAULT 'substituicao',
            data TEXT NOT NULL,
            funcionario_ausente_id INTEGER,
            funcionario_substituto_id INTEGER,
            turno_id INTEGER NOT NULL,
            motivo TEXT NOT NULL,
            descricao TEXT,
            criado_por INTEGER NOT NULL,
            FOREIGN KEY (funcionario_ausente_id) REFERENCES funcionarios(id),
            FOREIGN KEY (funcionario_substituto_id) REFERENCES funcionarios(id),
            FOREIGN KEY (turno_id) REFERENCES turnos(id),
            FOREIGN KEY (criado_por) REFERENCES utilizadores(id)
        );

        CREATE TABLE anos_laborais (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ano INTEGER NOT NULL,
            estado TEXT NOT NULL DEFAULT 'pendente',
            dia_inicio_semana INTEGER NOT NULL DEFAULT 1,
            dia_fim_semana INTEGER NOT NULL DEFAULT 5,
            activado_em TEXT,
            activado_por INTEGER,
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (ano)
        );

        CREATE TABLE feriados (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            data TEXT NOT NULL,
            tipo TEXT NOT NULL DEFAULT 'nacional',
            meio_dia INTEGER NOT NULL DEFAULT 0,
            recorrente INTEGER NOT NULL DEFAULT 1,
            ano INTEGER,
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE horarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            tipo TEXT NOT NULL DEFAULT 'normal',
            horas_dia REAL NOT NULL DEFAULT 8.00,
            horas_semana REAL NOT NULL DEFAULT 44.00,
            tolerancia_entrada_min INTEGER NOT NULL DEFAULT 10,
            intervalo_min INTEGER NOT NULL DEFAULT 60,
            activo INTEGER NOT NULL DEFAULT 1,
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE horario_turnos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            horario_id INTEGER NOT NULL,
            dia_semana INTEGER NOT NULL,
            hora_entrada TEXT NOT NULL,
            hora_saida TEXT NOT NULL,
            hora_inicio_intervalo TEXT,
            hora_fim_intervalo TEXT,
            dia_folga INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (horario_id) REFERENCES horarios(id) ON DELETE CASCADE
        );

        CREATE TABLE funcionario_horario (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            funcionario_id INTEGER NOT NULL,
            horario_id INTEGER NOT NULL,
            data_inicio TEXT NOT NULL,
            data_fim TEXT,
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE,
            FOREIGN KEY (horario_id) REFERENCES horarios(id)
        );
    ");

    return $pdo;
}
