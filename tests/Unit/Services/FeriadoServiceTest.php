<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\FeriadoService;
use PHPUnit\Framework\TestCase;
use PDO;

class FeriadoServiceTest extends TestCase
{
    private PDO $pdo;
    private FeriadoService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("
            CREATE TABLE feriados (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                data TEXT NOT NULL,
                tipo TEXT DEFAULT 'nacional',
                meio_dia INTEGER DEFAULT 0,
                recorrente INTEGER DEFAULT 1,
                ano INTEGER,
                criado_em TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->pdo->exec("CREATE UNIQUE INDEX uq_feriado_data_nome ON feriados (data, nome)");

        $this->service = new FeriadoService($this->pdo);
    }

    public function testCalcularFeriadosMoveis2024(): void
    {
        $moveis = FeriadoService::calcularFeriadosMoveis(2024);

        // Páscoa 2024 é 31 Março
        // Carnaval = 31 Mar - 47 dias = 13 Fev
        // Sexta-feira Santa = 31 Mar - 2 dias = 29 Mar

        $this->assertEquals('2024-02-13', $moveis['carnaval']);
        $this->assertEquals('2024-03-29', $moveis['sexta_santa']);
    }

    public function testCalcularFeriadosMoveis2025(): void
    {
        $moveis = FeriadoService::calcularFeriadosMoveis(2025);

        // Páscoa 2025 é 20 Abril
        // Carnaval = 20 Abr - 47 dias = 4 Mar
        // Sexta-feira Santa = 20 Abr - 2 dias = 18 Abr

        $this->assertEquals('2025-03-04', $moveis['carnaval']);
        $this->assertEquals('2025-04-18', $moveis['sexta_santa']);
    }

    public function testPreCarregarFeriadosMoveis(): void
    {
        $inseridos = $this->service->preCarregarFeriadosMoveis(2024);

        // 2 móveis
        $this->assertEquals(2, $inseridos);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM feriados WHERE nome = 'Carnaval' AND data = '2024-02-13'");
        $this->assertEquals(1, $stmt->fetchColumn());

        // Idempotência
        $inseridos2 = $this->service->preCarregarFeriadosMoveis(2024);
        $this->assertEquals(0, $inseridos2);
    }

    public function testIsFeriado(): void
    {
        $this->pdo->exec("INSERT INTO feriados (nome, data) VALUES ('Teste', '2024-12-31')");

        $this->assertTrue($this->service->isFeriado('2024-12-31'));
        $this->assertFalse($this->service->isFeriado('2024-12-30'));
    }

    public function testListarFeriadosEntre(): void
    {
        $this->pdo->exec("INSERT INTO feriados (nome, data) VALUES ('Ano Novo', '2024-01-01')");
        $this->service->preCarregarFeriadosMoveis(2024);

        $feriados = $this->service->listarFeriadosEntre('2024-01-01', '2024-02-13');

        $this->assertCount(2, $feriados);
        $this->assertEquals('Ano Novo', $feriados[0]['nome']);
        $this->assertEquals('Carnaval', $feriados[1]['nome']);
    }
}
