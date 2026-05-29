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
            nome TEXT NOT NULL
        );

        CREATE TABLE horarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL
        );

        CREATE TABLE rotacoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            horario_id INTEGER NOT NULL,
            nome TEXT NOT NULL,
            tipo TEXT NOT NULL DEFAULT 'ciclo_on_off',
            ignora_fds INTEGER NOT NULL DEFAULT 0,
            ignora_feriados INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (horario_id) REFERENCES horarios(id)
        );

        CREATE TABLE rotacao_fases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rotacao_id INTEGER NOT NULL,
            ordem INTEGER NOT NULL,
            nome TEXT NOT NULL,
            tipo_fase TEXT NOT NULL DEFAULT 'trabalho',
            duracao_dias INTEGER NOT NULL DEFAULT 1,
            hora_entrada TEXT,
            hora_saida TEXT,
            hora_inicio_intervalo TEXT,
            hora_fim_intervalo TEXT,
            horas_dia REAL,
            FOREIGN KEY (rotacao_id) REFERENCES rotacoes(id)
        );

        CREATE TABLE funcionario_rotacao (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            funcionario_id INTEGER NOT NULL,
            rotacao_id INTEGER NOT NULL,
            data_inicio TEXT NOT NULL,
            data_fim TEXT,
            fase_inicial INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id),
            FOREIGN KEY (rotacao_id) REFERENCES rotacoes(id)
        );
    ");

    return $pdo;
}
