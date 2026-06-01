<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EscalaService;
use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../bootstrap_db.php';

class EscalaServiceTest extends TestCase
{
    private PDO $pdo;
    private EscalaService $service;

    protected function setUp(): void
    {
        $this->pdo = bootstrap_db();
        $this->service = new EscalaService($this->pdo);

        // Criar dados básicos comuns
        $this->pdo->exec("INSERT INTO funcionarios (id, nome) VALUES (1, 'João')");
        $this->pdo->exec("INSERT INTO funcionarios (id, nome) VALUES (2, 'Maria')");
        $this->pdo->exec("INSERT INTO utilizadores (id, nome) VALUES (1, 'Admin')");
    }

    public function test_ciclo_5_2_primeiro_dia_e_trabalho(): void
    {
        // Turnos
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo, hora_entrada, hora_saida) VALUES (1, 'Trabalho', 'trabalho', '08:00', '17:00')");
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo) VALUES (2, 'Folga', 'folga')");

        // Escala 5/2 (7 posições: 5 T, 2 F)
        $this->pdo->exec("INSERT INTO escalas (id, nome, tamanho_ciclo) VALUES (1, '5/2', 7)");
        for ($i = 1; $i <= 5; $i++) {
            $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, $i, 1)");
        }
        for ($i = 6; $i <= 7; $i++) {
            $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, $i, 2)");
        }

        // Segunda-feira, 2024-01-01. Joao com posicao_inicial=1
        $this->pdo->exec("INSERT INTO funcionario_escala (funcionario_id, escala_id, data_inicio, posicao_inicial) VALUES (1, 1, '2024-01-01', 1)");

        $turno = $this->service->calcularTurnoEm(1, '2024-01-01');

        $this->assertNotNull($turno);
        $this->assertEquals('trabalho', $turno['tipo']);
        $this->assertEquals('08:00', $turno['hora_entrada']);
        $this->assertEquals(1, $turno['posicao_no_ciclo']);
    }

    public function test_ciclo_5_2_sexto_dia_e_folga(): void
    {
        // Turnos
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo) VALUES (1, 'Trabalho', 'trabalho')");
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo) VALUES (2, 'Folga', 'folga')");

        // Escala 5/2
        $this->pdo->exec("INSERT INTO escalas (id, nome, tamanho_ciclo) VALUES (1, '5/2', 7)");
        for ($i = 1; $i <= 5; $i++) {
            $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, $i, 1)");
        }
        for ($i = 6; $i <= 7; $i++) {
            $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, $i, 2)");
        }

        // Segunda-feira, 2024-01-01. Sexto dia é Sábado, 2024-01-06.
        $this->pdo->exec("INSERT INTO funcionario_escala (funcionario_id, escala_id, data_inicio, posicao_inicial) VALUES (1, 1, '2024-01-01', 1)");

        $turno = $this->service->calcularTurnoEm(1, '2024-01-06');

        $this->assertNotNull($turno);
        $this->assertEquals('folga', $turno['tipo']);
        $this->assertEquals(6, $turno['posicao_no_ciclo']);
    }

    public function test_funcionario_sem_escala_retorna_null(): void
    {
        $turno = $this->service->calcularTurnoEm(1, '2024-01-01');
        $this->assertNull($turno);
    }

    public function test_escala_terminada_retorna_null(): void
    {
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo) VALUES (1, 'Trabalho', 'trabalho')");
        $this->pdo->exec("INSERT INTO escalas (id, nome, tamanho_ciclo) VALUES (1, 'Fixa', 1)");
        $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, 1, 1)");

        // Escala terminou em 2023-12-31
        $this->pdo->exec("INSERT INTO funcionario_escala (funcionario_id, escala_id, data_inicio, data_fim, posicao_inicial) VALUES (1, 1, '2023-01-01', '2023-12-31', 1)");

        $turno = $this->service->calcularTurnoEm(1, '2024-01-01');
        $this->assertNull($turno);
    }

    public function test_call_center_5_posicoes_rotacao_diaria(): void
    {
        // Turnos A, B, Saida, Folga1, Folga2
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo) VALUES (1, 'TurnoA', 'trabalho')");
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo) VALUES (2, 'TurnoB', 'trabalho')");
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo) VALUES (3, 'Saida', 'trabalho')");
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo) VALUES (4, 'Folga1', 'folga')");
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo) VALUES (5, 'Folga2', 'folga')");

        $this->pdo->exec("INSERT INTO escalas (id, nome, tamanho_ciclo) VALUES (1, '5x5', 5)");
        $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, 1, 1)");
        $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, 2, 2)");
        $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, 3, 3)");
        $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, 4, 4)");
        $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, 5, 5)");

        $this->pdo->exec("INSERT INTO funcionario_escala (funcionario_id, escala_id, data_inicio, posicao_inicial) VALUES (1, 1, '2024-01-01', 1)");

        // Dia 1 (2024-01-01) -> TurnoA
        $t1 = $this->service->calcularTurnoEm(1, '2024-01-01');
        $this->assertEquals('TurnoA', $t1['turno_nome']);

        // Dia 2 (2024-01-02) -> TurnoB
        $t2 = $this->service->calcularTurnoEm(1, '2024-01-02');
        $this->assertEquals('TurnoB', $t2['turno_nome']);

        // Dia 5 (2024-01-05) -> Folga2
        $t5 = $this->service->calcularTurnoEm(1, '2024-01-05');
        $this->assertEquals('Folga2', $t5['turno_nome']);

        // Dia 6 (2024-01-06) -> Volta a TurnoA
        $t6 = $this->service->calcularTurnoEm(1, '2024-01-06');
        $this->assertEquals('TurnoA', $t6['turno_nome']);
    }

    public function test_posicoes_iniciais_distintas_no_mesmo_dia(): void
    {
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo) VALUES (1, 'TurnoA', 'trabalho')");
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo) VALUES (2, 'TurnoB', 'trabalho')");
        $this->pdo->exec("INSERT INTO escalas (id, nome, tamanho_ciclo) VALUES (1, 'Rotativa', 2)");
        $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, 1, 1)");
        $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, 2, 2)");

        // Joao começa na pos 1, Maria na pos 2 no mesmo dia
        $this->pdo->exec("INSERT INTO funcionario_escala (funcionario_id, escala_id, data_inicio, posicao_inicial) VALUES (1, 1, '2024-01-01', 1)");
        $this->pdo->exec("INSERT INTO funcionario_escala (funcionario_id, escala_id, data_inicio, posicao_inicial) VALUES (2, 1, '2024-01-01', 2)");

        $tJoao = $this->service->calcularTurnoEm(1, '2024-01-01');
        $tMaria = $this->service->calcularTurnoEm(2, '2024-01-01');

        $this->assertEquals('TurnoA', $tJoao['turno_nome']);
        $this->assertEquals('TurnoB', $tMaria['turno_nome']);
    }

    public function test_excepcao_substituicao_retorna_turno_coberto(): void
    {
        // Configurar Turnos e Escala
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo, hora_entrada, hora_saida) VALUES (1, 'Turno Normal', 'trabalho', '08:00', '17:00')");
        $this->pdo->exec("INSERT INTO turnos (id, nome, tipo) VALUES (2, 'Folga', 'folga')");

        $this->pdo->exec("INSERT INTO escalas (id, nome, tamanho_ciclo) VALUES (1, '5/2', 7)");
        $this->pdo->exec("INSERT INTO escala_turnos (escala_id, posicao, turno_id) VALUES (1, 1, 1)");

        // Joao (1) está de escala no dia 2024-01-01
        $this->pdo->exec("INSERT INTO funcionario_escala (funcionario_id, escala_id, data_inicio, posicao_inicial) VALUES (1, 1, '2024-01-01', 1)");

        // Maria (2) não tem escala ou está de folga
        $this->pdo->exec("INSERT INTO funcionario_escala (funcionario_id, escala_id, data_inicio, posicao_inicial) VALUES (2, 1, '2024-01-01', 6)"); // Maria começa na folga

        // Excepção: Maria substitui Joao em 2024-01-01
        $this->pdo->exec("INSERT INTO escala_excepcoes (data, funcionario_ausente_id, funcionario_substituto_id, turno_id, motivo, criado_por)
                          VALUES ('2024-01-01', 1, 2, 1, 'doenca', 1)");

        // Calcular turno para Maria
        $tMaria = $this->service->calcularTurnoEm(2, '2024-01-01');

        $this->assertNotNull($tMaria);
        $this->assertEquals('trabalho', $tMaria['tipo']);
        $this->assertEquals('Turno Normal', $tMaria['turno_nome']);
        $this->assertEquals('substituto', $tMaria['substituicao']['tipo']);
        $this->assertEquals(1, $tMaria['substituicao']['funcionario_ausente_id']);

        // Calcular turno para Joao (deve estar como ausente)
        $tJoao = $this->service->calcularTurnoEm(1, '2024-01-01');
        $this->assertNotNull($tJoao);
        $this->assertEquals('folga', $tJoao['tipo']); // Virtualmente folga
        $this->assertEquals('ausente', $tJoao['substituicao']['tipo']);
        $this->assertEquals(2, $tJoao['substituicao']['funcionario_substituto_id']);
    }
}
