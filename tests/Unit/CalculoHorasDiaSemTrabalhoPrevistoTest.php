<?php

declare(strict_types=1);

use App\Services\CalculoHorasService;
use PHPUnit\Framework\TestCase;

final class CalculoHorasDiaSemTrabalhoPrevistoTest extends TestCase
{
    private const DESDE = '2026-10-21';

    private function turno(string $tipo = 'normal'): array
    {
        return [
            'tipo' => $tipo, 'hora_entrada' => '08:00:00', 'hora_saida' => '17:00:00',
            'horas_efectivas' => $tipo === 'folga' ? null : 8, 'tolerancia_entrada_min' => 10,
            'atravessa_dia_civil' => 0,
        ];
    }

    private function dia(array $horas, ?array $turno, string $tipoDia, string $regime, string $data, int $aprovados, ?string $desde): array
    {
        $m = [];
        foreach ($horas as $tipo => $hora) {
            $m[] = ['tipo' => $tipo, 'data_hora' => $data . ' ' . $hora];
        }
        return (new CalculoHorasService())->calcularDia(
            $m, $turno, $tipoDia, $regime, $data,
            false, false, false, true, false, $aprovados, null, $desde
        );
    }

    public function testSabadoSoEntradaSemAprovacaoValeZero(): void
    {
        $r = $this->dia(['entrada' => '07:44:00'], null, 'sabado', 'normal', '2026-11-07', 0, self::DESDE);
        $this->assertSame(0.0, $r['horas_trabalhadas']);
        $this->assertSame(0, $r['minutos_totais']);
        $this->assertSame('sem_aprovacao', $r['tipo_presenca']);
        $this->assertSame('07:44', $r['primeira_entrada_str']);
    }

    public function testDomingoParCompletoSemAprovacaoValeZero(): void
    {
        $r = $this->dia(['entrada' => '08:00:00', 'saida' => '17:00:00'], null, 'domingo', 'normal', '2026-11-08', 0, self::DESDE);
        $this->assertSame(0.0, $r['horas_trabalhadas']);
        $this->assertSame(0, $r['minutos_extra_extraordinario']);
        $this->assertSame('sem_aprovacao', $r['tipo_presenca']);
    }

    public function testFeriadoParCompletoSemAprovacaoValeZero(): void
    {
        $r = $this->dia(['entrada' => '08:00:00', 'saida' => '17:00:00'], $this->turno(), 'feriado', 'normal', '2026-11-11', 0, self::DESDE);
        $this->assertSame(0.0, $r['horas_trabalhadas']);
        $this->assertSame('sem_aprovacao', $r['tipo_presenca']);
    }

    public function testSabadoComAprovacaoContaAteAoAprovado(): void
    {
        $r = $this->dia(['entrada' => '08:00:00', 'saida' => '17:00:00'], null, 'sabado', 'normal', '2026-11-07', 240, self::DESDE);
        $this->assertSame(240, $r['minutos_extra_extraordinario']);
        $this->assertSame(240, $r['minutos_totais']);
        $this->assertSame(4.0, $r['horas_trabalhadas']);
        $this->assertSame('completo', $r['tipo_presenca']);
    }

    public function testSabadoAprovadoMasSoUmaPicagemNaoConta(): void
    {
        $r = $this->dia(['entrada' => '08:00:00'], null, 'sabado', 'normal', '2026-11-07', 240, self::DESDE);
        $this->assertSame(0.0, $r['horas_trabalhadas']);
        $this->assertSame('picagem_incompleta', $r['tipo_presenca']);
    }

    public function testFolgaEmDiaUtilSemAprovacaoValeZero(): void
    {
        $r = $this->dia(['entrada' => '08:00:00', 'saida' => '17:00:00'], $this->turno('folga'), 'util', 'turnos', '2026-11-10', 0, self::DESDE);
        $this->assertSame(0.0, $r['horas_trabalhadas']);
        $this->assertSame('sem_aprovacao', $r['tipo_presenca']);
    }

    public function testTurnoMarcadoAoSabadoNaoEAfectado(): void
    {
        $r = $this->dia(['entrada' => '08:00:00', 'saida' => '17:00:00'], $this->turno(), 'sabado', 'turnos', '2026-11-07', 0, self::DESDE);
        $this->assertSame(8.0, $r['horas_trabalhadas']);
        $this->assertSame('completo', $r['tipo_presenca']);
    }

    public function testDiaUtilNormalNaoEAfectado(): void
    {
        $r = $this->dia(['entrada' => '08:00:00', 'saida' => '17:00:00'], $this->turno(), 'util', 'normal', '2026-11-10', 0, self::DESDE);
        $this->assertSame(8.0, $r['horas_trabalhadas']);
        $this->assertSame('completo', $r['tipo_presenca']);

        $meio = $this->dia(['entrada' => '08:13:00'], $this->turno(), 'util', 'normal', '2026-11-06', 0, self::DESDE);
        $this->assertSame(4.0, $meio['horas_trabalhadas']);
        $this->assertSame('meio_dia', $meio['tipo_presenca']);
    }

    public function testAntesDaDataOuSemConfiguracaoNaoMuda(): void
    {
        $antes = $this->dia(['entrada' => '07:44:00'], null, 'sabado', 'normal', '2026-10-17', 0, self::DESDE);
        $this->assertSame(4.0, $antes['horas_trabalhadas']);
        $this->assertSame('meio_dia', $antes['tipo_presenca']);

        $semConfig = $this->dia(['entrada' => '08:00:00', 'saida' => '17:00:00'], null, 'sabado', 'normal', '2026-11-07', 0, null);
        $this->assertSame(8.0, $semConfig['horas_trabalhadas']);
        $this->assertSame('completo', $semConfig['tipo_presenca']);
    }
}
