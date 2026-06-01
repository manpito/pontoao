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

        CREATE TABLE departamentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL
        );

        CREATE TABLE utilizadores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL
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
            data TEXT NOT NULL,
            funcionario_ausente_id INTEGER NOT NULL,
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
    ");

    return $pdo;
}
