<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EscalaService;
use App\Services\FeriadoService;
use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../bootstrap_db.php';

class CalendarioTrabalhoTest extends TestCase
{
    private PDO $pdo;
    private EscalaService $escalaService;

    protected function setUp(): void
    {
        $this->pdo = bootstrap_db();
        $this->escalaService = new EscalaService($this->pdo);

        // Dados base
        $this->pdo->exec("INSERT INTO funcionarios (id, nome) VALUES (1, 'Joao')");
        $this->pdo->exec("INSERT INTO utilizadores (id, nome) VALUES (1, 'Admin')");
    }

    public function test_regime_normal_segue_calendario_gregoriano(): void
    {
        // 1. Configurar horário normal (Seg-Sex 08-17)
        $this->pdo->exec("INSERT INTO horarios (id, nome, tipo, horas_dia) VALUES (1, 'Horario Normal', 'normal', 8.0)");

        // Segunda a Sexta (1 a 5) como trabalho
        for ($i = 1; $i <= 5; $i++) {
            $this->pdo->exec("INSERT INTO horario_turnos (horario_id, dia_semana, hora_entrada, hora_saida, dia_folga)
                              VALUES (1, $i, '08:00', '17:00', 0)");
        }
        // Sábado e Domingo (6 e 7) como folga
        for ($i = 6; $i <= 7; $i++) {
            $this->pdo->exec("INSERT INTO horario_turnos (horario_id, dia_semana, hora_entrada, hora_saida, dia_folga)
                              VALUES (1, $i, '00:00', '00:00', 1)");
        }

        // 2. Atribuir horário ao funcionário
        $this->pdo->exec("INSERT INTO funcionario_horario (funcionario_id, horario_id, data_inicio) VALUES (1, 1, '2024-01-01')");

        // 3. Configurar escala em regime 'normal'
        $this->pdo->exec("INSERT INTO escalas (id, nome, tamanho_ciclo, regime) VALUES (1, 'Escritorio', 7, 'normal')");
        $this->pdo->exec("INSERT INTO funcionario_escala (funcionario_id, escala_id, data_inicio, posicao_inicial) VALUES (1, 1, '2024-01-01', 1)");

        // Testar Segunda-feira (2024-01-01) -> Deve ser trabalho
        $t1 = $this->escalaService->calcularTurnoEm(1, '2024-01-01');
        $this->assertEquals('trabalho', $t1['tipo']);
        $this->assertEquals('08:00', $t1['hora_entrada']);

        // Testar Sábado (2024-01-06) -> Deve ser folga
        $t6 = $this->escalaService->calcularTurnoEm(1, '2024-01-06');
        $this->assertEquals('folga', $t6['tipo']);
        $this->assertEquals('Folga', $t6['turno_nome']);
    }

    public function test_regime_normal_detecta_feriado(): void
    {
        // Setup igual ao anterior
        $this->pdo->exec("INSERT INTO horarios (id, nome, tipo, horas_dia) VALUES (1, 'Horario Normal', 'normal', 8.0)");
        $this->pdo->exec("INSERT INTO horario_turnos (horario_id, dia_semana, hora_entrada, hora_saida, dia_folga) VALUES (1, 1, '08:00', '17:00', 0)");
        $this->pdo->exec("INSERT INTO funcionario_horario (funcionario_id, horario_id, data_inicio) VALUES (1, 1, '2024-01-01')");
        $this->pdo->exec("INSERT INTO escalas (id, nome, tamanho_ciclo, regime) VALUES (1, 'Escritorio', 7, 'normal')");
        $this->pdo->exec("INSERT INTO funcionario_escala (funcionario_id, escala_id, data_inicio, posicao_inicial) VALUES (1, 1, '2024-01-01', 1)");

        // Inserir feriado em 2024-01-01 (Segunda-feira)
        $this->pdo->exec("INSERT INTO feriados (nome, data, tipo, recorrente) VALUES ('Ano Novo', '2024-01-01', 'nacional', 1)");

        $t1 = $this->escalaService->calcularTurnoEm(1, '2024-01-01');
        $this->assertEquals('folga', $t1['tipo']);
        $this->assertEquals('Feriado', $t1['turno_nome']);
    }
}
