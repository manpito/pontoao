<?php

declare(strict_types=1);

use App\Services\CalculoHorasService;
use PHPUnit\Framework\TestCase;

final class CalculoHorasAprovacaoObrigatoriaTest extends TestCase
{
    private function sabado(int $aprovados, ?string $desde, string $data = '2026-12-05'): array
    {
        $m = [
            ['tipo' => 'entrada', 'data_hora' => $data . ' 08:00:00'],
            ['tipo' => 'saida',   'data_hora' => $data . ' 17:00:00'],
        ];
        return (new CalculoHorasService())->calcularDia(
            $m, null, 'sabado', 'normal', $data,
            false, false, false, true, false, $aprovados, null, $desde
        );
    }

    public function testSemConfiguracaoContaTudo(): void
    {
        $r = $this->sabado(0, null);
        $this->assertSame(480, $r['minutos_extra_extraordinario']);
    }

    public function testComConfiguracaoSemAprovacaoNaoConta(): void
    {
        $r = $this->sabado(0, '2026-12-03');
        $this->assertSame(0, $r['minutos_extra_extraordinario']);
        $this->assertSame(480, $r['minutos_totais']);
    }

    public function testComConfiguracaoContaAteAoAprovado(): void
    {
        $r = $this->sabado(240, '2026-12-03');
        $this->assertSame(240, $r['minutos_extra_extraordinario']);
    }

    public function testAntesDaDataNaoMuda(): void
    {
        $r = $this->sabado(0, '2026-12-03', '2026-11-28');
        $this->assertSame(480, $r['minutos_extra_extraordinario']);
    }
}
