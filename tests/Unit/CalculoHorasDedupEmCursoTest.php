<?php

declare(strict_types=1);

use App\Services\CalculoHorasService;
use PHPUnit\Framework\TestCase;

final class CalculoHorasDedupEmCursoTest extends TestCase
{
    public function testDeduplicarMantemPrimeiraDentroDe120s(): void
    {
        $m = [
            ['tipo' => 'entrada', 'data_hora' => '2026-10-20 07:52:54'],
            ['tipo' => 'entrada', 'data_hora' => '2026-10-20 07:52:53'],
            ['tipo' => 'saida',   'data_hora' => '2026-10-20 17:00:00'],
        ];
        $r = CalculoHorasService::deduplicarMarcacoes($m);
        $this->assertCount(2, $r);
        $this->assertSame('2026-10-20 07:52:53', $r[0]['data_hora']);
    }

    public function testDeduplicarMantemPicagensAfastadas(): void
    {
        $m = [
            ['tipo' => 'entrada', 'data_hora' => '2026-10-20 07:00:00'],
            ['tipo' => 'entrada', 'data_hora' => '2026-10-20 07:05:00'],
        ];
        $this->assertCount(2, CalculoHorasService::deduplicarMarcacoes($m));
    }

    public function testEntradaDuplicadaNaoFicaIncoerente(): void
    {
        $svc = new CalculoHorasService();
        $m = [
            ['tipo' => 'entrada', 'data_hora' => '2026-10-20 07:52:53'],
            ['tipo' => 'entrada', 'data_hora' => '2026-10-20 07:52:54'],
            ['tipo' => 'saida',   'data_hora' => '2026-10-20 17:00:00'],
        ];
        $r = $svc->calcularDia($m, null, 'util', 'normal', '2026-10-20');
        $this->assertSame('completo', $r['tipo_presenca']);
        $this->assertFalse($r['is_incoerente']);
    }

    public function testDiaCorrenteSoComEntradaFicaEmCurso(): void
    {
        $hoje = (new \DateTime('now', new \DateTimeZone('Africa/Luanda')))->format('Y-m-d');
        $svc = new CalculoHorasService();
        $r = $svc->calcularDia([['tipo' => 'entrada', 'data_hora' => $hoje . ' 00:00:01']], null, 'util', 'normal', $hoje);
        $this->assertSame('em_curso', $r['tipo_presenca']);
        $this->assertSame(0.0, $r['horas_trabalhadas']);
    }

    public function testDiaPassadoSoComEntradaContinuaMeioDia(): void
    {
        $svc = new CalculoHorasService();
        $r = $svc->calcularDia([['tipo' => 'entrada', 'data_hora' => '2026-10-20 07:30:00']], null, 'util', 'normal', '2026-10-20');
        $this->assertSame('meio_dia', $r['tipo_presenca']);
        $this->assertSame(4.0, $r['horas_trabalhadas']);
    }
}
