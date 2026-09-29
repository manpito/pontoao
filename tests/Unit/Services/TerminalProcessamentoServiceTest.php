<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\TerminalProcessamentoService;
use PDO;
use PHPUnit\Framework\TestCase;

class TerminalProcessamentoServiceTest extends TestCase
{
    private PDO $db;
    private TerminalProcessamentoService $service;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../../tests/bootstrap_db.php';
        $this->db = bootstrap_db();


        $this->db->sqliteCreateFunction('TIMESTAMPDIFF', function ($unit, $dt1, $dt2) {
            $ts1 = strtotime($dt1);
            $ts2 = strtotime($dt2);
            return $ts2 - $ts1;
        }, 3);

        // Allow 'periodo_fechado' in our memory schema for tests since SQLite doesn't strictly enforce ENUMs,
        // but it's good to have the DB schema correctly loaded.

        $this->service = new TerminalProcessamentoService();
    }

    public function testProcessarRegistoEmPeriodoFechadoLancaExcecaoEGeraAviso(): void
    {
        // Ensure periodos_mensais schema exists for testing
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS periodos_mensais (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ano INTEGER NOT NULL,
                mes INTEGER NOT NULL,
                estado TEXT NOT NULL DEFAULT 'aberto',
                data_inicio TEXT,
                data_fim TEXT
            );
        ");

        // Ensure marcacoes table exists
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS marcacoes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                funcionario_id INTEGER,
                tipo TEXT,
                data_hora TEXT,
                data_hora_original TEXT,
                origem TEXT,
                relogio_id INTEGER,
                ip_marcacao TEXT,
                SECOND INTEGER DEFAULT 0
            );
        ");

        // 1. Criar um funcionário activo
        $this->db->exec("
            INSERT INTO funcionarios (id, numero_funcionario, nome_completo, nome, estado, departamento_id, cargo_id)
            VALUES (1, '9999', 'Teste Func', 'Teste Func', 'activo', 1, 1)
        ");

        // 2. Criar um período mensal fechado para Janeiro de 2024
        // (O teste usa a data de marcação '2024-01-15 10:00:00')
        $this->db->exec("
            INSERT INTO periodos_mensais (ano, mes, estado, data_inicio, data_fim)
            VALUES (2024, 1, 'fechado', '2024-01-01', '2024-01-31')
        ");

        // Relógio simulado
        $relogio = [
            'id' => 1,
            'nome' => 'Relogio Teste',
            'device_id' => 'SN12345'
        ];

        // Registo vindo do relógio
        $registo = [
            'UserID' => '9999',
            'Timestamp' => '2024-01-15 10:00:00',
            'Status' => 0, // Entrada
            'Verified' => 1
        ];

        // 3. Executar o processamento. Como o período está fechado, deve lançar excepção.
        $excecaoLancada = false;
        try {
            $this->service->processarRegisto($this->db, $relogio, $registo);
        } catch (\RuntimeException $e) {
            $this->assertEquals("Período mensal fechado — marcação rejeitada.", $e->getMessage());
            $excecaoLancada = true;
        }

        $this->assertTrue($excecaoLancada, "Deveria ter lançado excepção de período fechado.");

        // 4. Validar que gravou em adms_avisos
        $stmt = $this->db->query("SELECT * FROM adms_avisos WHERE tipo = 'periodo_fechado'");
        $avisos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $avisos, "Deveria ter registado um aviso em adms_avisos.");
        $aviso = $avisos[0];

        $this->assertEquals('periodo_fechado', $aviso['tipo']);
        $this->assertEquals('SN12345', $aviso['sn_relogio']);
        $this->assertEquals('9999', $aviso['numero_funcionario']);

        $payloadArray = json_decode($aviso['payload_bruto'], true);
        $this->assertIsArray($payloadArray);
        $this->assertEquals('9999', $payloadArray['UserID']);
        $this->assertEquals('2024-01-15 10:00:00', $payloadArray['Timestamp']);
    }
}
