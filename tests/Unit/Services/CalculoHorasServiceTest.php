<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CalculoHorasService;
use PHPUnit\Framework\TestCase;

class CalculoHorasServiceTest extends TestCase
{
    private CalculoHorasService $service;

    protected function setUp(): void
    {
        $this->service = new CalculoHorasService();
    }

    public function testDiaCompletoNormal(): void
    {
        $marcacoes = [
            ['tipo' => 'entrada', 'data_hora' => '2023-10-02 08:00:00'], // Segunda-feira
            ['tipo' => 'saida', 'data_hora' => '2023-10-02 18:00:00']
        ];

        $turno = [
            'tipo' => 'normal',
            'horas_efectivas' => 8,
            'atravessa_dia_civil' => 0,
            'hora_entrada' => '08:00:00',
            'hora_saida' => '17:00:00',
            'tolerancia_entrada_min' => 10
        ];

        $resultado = $this->service->calcularDia($marcacoes, $turno, 'util', 'normal', '2023-10-02');

        $this->assertEquals('completo', $resultado['tipo_presenca']);
        $this->assertEquals(9, $resultado['horas_trabalhadas']); // (18-8) - 1h fixa de almoço
        $this->assertEquals(60, $resultado['minutos_extra']); // Trabalhou 9h, mas turno pede 8h. Então tem 1h extra
        $this->assertEquals(0, $resultado['minutos_extra_extraordinario']);
    }

    public function testDiaCompletoFimDeSemana(): void
    {
        $marcacoes = [
            ['tipo' => 'entrada', 'data_hora' => '2023-10-07 08:00:00'], // Sábado
            ['tipo' => 'saida', 'data_hora' => '2023-10-07 14:00:00']
        ];

        $turno = [
            'tipo' => 'folga', // Folga de fds
            'horas_efectivas' => null,
            'atravessa_dia_civil' => 0,
            'hora_entrada' => null,
            'hora_saida' => null
        ];

        $resultado = $this->service->calcularDia($marcacoes, $turno, 'sabado', 'normal', '2023-10-07');

        $this->assertEquals('completo', $resultado['tipo_presenca']);
        $this->assertEquals(5, $resultado['horas_trabalhadas']); // (14-8) - 1h
        $this->assertEquals(0, $resultado['minutos_extra']);
        $this->assertEquals(300, $resultado['minutos_extra_extraordinario']); // 5 * 60 minutos
    }

    public function testMeioDia(): void
    {
        $marcacoes = [
            ['tipo' => 'entrada', 'data_hora' => '2023-10-02 08:00:00'] // Apenas entrada
        ];

        $turno = [
            'tipo' => 'normal',
            'horas_efectivas' => 8,
            'atravessa_dia_civil' => 0,
            'hora_entrada' => '08:00:00',
            'hora_saida' => '17:00:00'
        ];

        $resultado = $this->service->calcularDia($marcacoes, $turno, 'util', 'normal', '2023-10-02');

        $this->assertEquals('meio_dia', $resultado['tipo_presenca']);
        $this->assertEquals(4.0, $resultado['horas_trabalhadas']); // Valor fixo
        $this->assertEquals(0, $resultado['minutos_extra']);
    }

    public function testAusente(): void
    {
        $resultado = $this->service->calcularDia([], null, 'util', 'normal', '2023-10-02');
        $this->assertEquals('ausente', $resultado['tipo_presenca']);
        $this->assertEquals(0.0, $resultado['horas_trabalhadas']);
    }
}
