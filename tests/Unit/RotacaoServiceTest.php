<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RotacaoService;
use PHPUnit\Framework\TestCase;
use PDO;

require_once __DIR__ . '/../bootstrap_db.php';

class RotacaoServiceTest extends TestCase
{
    private PDO $pdo;
    private RotacaoService $service;

    protected function setUp(): void
    {
        $this->pdo = bootstrap_db();
        $this->service = new RotacaoService($this->pdo);

        // Criar dados básicos comuns
        $this->pdo->exec("INSERT INTO funcionarios (id, nome) VALUES (1, 'João')");
        $this->pdo->exec("INSERT INTO horarios (id, nome) VALUES (1, 'Horário Geral')");
    }

    public function test_ciclo_5_2_primeiro_dia_e_trabalho(): void
    {
        // Rotação 5/2 (5 dias trabalho, 2 dias folga)
        $this->pdo->exec("INSERT INTO rotacoes (id, horario_id, nome, ignora_fds) VALUES (1, 1, '5/2', 0)");
        $this->pdo->exec("INSERT INTO rotacao_fases (rotacao_id, ordem, nome, tipo_fase, duracao_dias, hora_entrada, hora_saida) VALUES (1, 1, 'Trabalho', 'trabalho', 5, '08:00', '17:00')");
        $this->pdo->exec("INSERT INTO rotacao_fases (rotacao_id, ordem, nome, tipo_fase, duracao_dias) VALUES (1, 2, 'Folga', 'folga', 2)");

        // Segunda-feira, 2024-01-01
        $this->pdo->exec("INSERT INTO funcionario_rotacao (funcionario_id, rotacao_id, data_inicio) VALUES (1, 1, '2024-01-01')");

        $fase = $this->service->calcularFaseEm(1, '2024-01-01');

        $this->assertNotNull($fase);
        $this->assertEquals('trabalho', $fase['fase']);
        $this->assertEquals('08:00', $fase['horario_esperado']['hora_entrada']);
    }

    public function test_ciclo_5_2_sexto_dia_e_folga(): void
    {
        // Rotação 5/2 (5 dias trabalho, 2 dias folga)
        $this->pdo->exec("INSERT INTO rotacoes (id, horario_id, nome, ignora_fds) VALUES (1, 1, '5/2', 0)");
        $this->pdo->exec("INSERT INTO rotacao_fases (rotacao_id, ordem, nome, tipo_fase, duracao_dias) VALUES (1, 1, 'Trabalho', 'trabalho', 5)");
        $this->pdo->exec("INSERT INTO rotacao_fases (rotacao_id, ordem, nome, tipo_fase, duracao_dias) VALUES (1, 2, 'Folga', 'folga', 2)");

        // Segunda-feira, 2024-01-01. Sexto dia é Sábado, 2024-01-06.
        $this->pdo->exec("INSERT INTO funcionario_rotacao (funcionario_id, rotacao_id, data_inicio) VALUES (1, 1, '2024-01-01')");

        $fase = $this->service->calcularFaseEm(1, '2024-01-06');

        $this->assertNotNull($fase);
        $this->assertEquals('folga', $fase['fase']);
    }

    public function test_funcionario_sem_rotacao_retorna_null(): void
    {
        $fase = $this->service->calcularFaseEm(1, '2024-01-01');
        $this->assertNull($fase);
    }

    public function test_rotacao_terminada_retorna_null(): void
    {
        $this->pdo->exec("INSERT INTO rotacoes (id, horario_id, nome) VALUES (1, 1, 'Fixa')");
        $this->pdo->exec("INSERT INTO rotacao_fases (rotacao_id, ordem, nome, tipo_fase, duracao_dias) VALUES (1, 1, 'Trabalho', 'trabalho', 1)");

        // Rotação terminou em 2023-12-31
        $this->pdo->exec("INSERT INTO funcionario_rotacao (funcionario_id, rotacao_id, data_inicio, data_fim) VALUES (1, 1, '2023-01-01', '2023-12-31')");

        $fase = $this->service->calcularFaseEm(1, '2024-01-01');
        $this->assertNull($fase);
    }

    public function test_ignora_fds_nao_avanca_em_sabado(): void
    {
        // Rotação 5/2 mas ignora FDS.
        // Se começar numa Segunda (01/01), o Sábado (06/01) ainda deve estar no dia 5 do ciclo (Trabalho).
        $this->pdo->exec("INSERT INTO rotacoes (id, horario_id, nome, ignora_fds) VALUES (1, 1, '5/2 Especial', 1)");
        $this->pdo->exec("INSERT INTO rotacao_fases (rotacao_id, ordem, nome, tipo_fase, duracao_dias) VALUES (1, 1, 'Trabalho', 'trabalho', 5)");
        $this->pdo->exec("INSERT INTO rotacao_fases (rotacao_id, ordem, nome, tipo_fase, duracao_dias) VALUES (1, 2, 'Folga', 'folga', 2)");

        $this->pdo->exec("INSERT INTO funcionario_rotacao (funcionario_id, rotacao_id, data_inicio) VALUES (1, 1, '2024-01-01')");

        // 2024-01-01 (Seg) - Dia 0 (ajustado)
        // 2024-01-02 (Ter) - Dia 1
        // 2024-01-03 (Qua) - Dia 2
        // 2024-01-04 (Qui) - Dia 3
        // 2024-01-05 (Sex) - Dia 4
        // 2024-01-06 (Sab) - Ainda Dia 4 porque ignora_fds=1. Dia 4 está dentro da fase de 5 dias.

        $fase = $this->service->calcularFaseEm(1, '2024-01-06');
        $this->assertNotNull($fase);
        $this->assertEquals('trabalho', $fase['fase']);

        // 2024-01-07 (Dom) - Ainda Dia 4
        $faseDom = $this->service->calcularFaseEm(1, '2024-01-07');
        $this->assertEquals('trabalho', $faseDom['fase']);

        // 2024-01-08 (Seg) - Dia 5 -> Deve entrar na fase de Folga (que começa na posição 5)
        $faseSeg = $this->service->calcularFaseEm(1, '2024-01-08');
        $this->assertEquals('folga', $faseSeg['fase']);
    }

    public function test_fase_inicial_2_comeca_na_fase_correcta(): void
    {
        // Ciclo 5 Trabalho / 2 Folga
        $this->pdo->exec("INSERT INTO rotacoes (id, horario_id, nome) VALUES (1, 1, '5/2')");
        $this->pdo->exec("INSERT INTO rotacao_fases (rotacao_id, ordem, nome, tipo_fase, duracao_dias) VALUES (1, 1, 'Trabalho', 'trabalho', 5)");
        $this->pdo->exec("INSERT INTO rotacao_fases (rotacao_id, ordem, nome, tipo_fase, duracao_dias) VALUES (1, 2, 'Folga', 'folga', 2)");

        // Começa na fase 2 (Folga) logo no primeiro dia
        $this->pdo->exec("INSERT INTO funcionario_rotacao (funcionario_id, rotacao_id, data_inicio, fase_inicial) VALUES (1, 1, '2024-01-01', 2)");

        $fase = $this->service->calcularFaseEm(1, '2024-01-01');

        $this->assertNotNull($fase);
        $this->assertEquals('folga', $fase['fase']);
    }
}
