<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CalculoHorasService;
use PHPUnit\Framework\TestCase;

class CalculoHorasServiceTest2 extends TestCase
{
    private CalculoHorasService $service;

    protected function setUp(): void
    {
        $this->service = new CalculoHorasService();
    }

    public function testAtrasoCalculadoCorretamenteComSegundos(): void
    {
        // 08:10:45 - O truncamento H:i daria 08:10, o que seria (10-0 = 10) que com tolerancia 10 não era atraso.
        // Usando o timestamp 8h10 e 45s, a diferença dá 10m45s -> arredonda para 11m, ultrapassando a tolerancia.
        $marcacoes = [
            ['tipo' => 'entrada', 'data_hora' => '2023-10-02 08:10:45'], // Segunda-feira
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

        // Diferença = 11 mins de atraso. Tolerancia de 10. Atraso final deve ser 1 min.
        $this->assertEquals(1, $resultado['atraso_minutos']);
    }
}
