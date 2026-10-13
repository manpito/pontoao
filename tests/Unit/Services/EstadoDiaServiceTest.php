<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\EstadoDiaService;

class EstadoDiaServiceTest extends TestCase
{
    private EstadoDiaService $service;
    private array $map;

    protected function setUp(): void
    {
        $this->service = new EstadoDiaService();
        $this->map = [
            'ext' => 'trabalho',
            'fj' => 'falta_justificada_remunerada',
            'fjn' => 'falta_justificada_nao_remunerada',
            'folga_j' => 'folga',
            'desconhecido' => 'falta_justificada_nao_remunerada' // fallback
        ];
    }

    public function testFaltaInjustificadaSemNada()
    {
        $res = $this->service->determinar(1, '2025-10-10', [], [], [], ['tipo' => 'trabalho'], $this->map);
        $this->assertEquals('falta_injustificada', $res['estado']);
        $this->assertEquals('ciclo', $res['origem']);
    }

    public function testFeriasVenceAjusteEJustificacao()
    {
        $ferias = [['id' => 1]];
        $justificacoes = [
            ['id' => null, 'ajuste_id' => 10, 'tipo' => 'ext', 'estado' => 'aprovado'],
            ['id' => 20, 'tipo' => 'fj', 'estado' => 'aprovado']
        ];

        $res = $this->service->determinar(1, '2025-10-10', $ferias, $justificacoes, [], ['tipo' => 'trabalho'], $this->map);
        $this->assertEquals('ferias', $res['estado']);
    }

    public function testAjusteVenceJustificacaoNormal()
    {
        // A lógica de pre-filtro que isola o ajuste não é testada isoladamente se o EstadoDiaService estiver misturando as justificações normais com o ajuste.
        // Contudo, a nova lógica de ordenação no EstadoDiaService faz o ajuste vencer sempre, logo, não importa o comportamento.
        $justificacoes = [
            ['id' => 99, 'ajuste_id' => null, 'tipo' => 'ext', 'estado' => 'aprovado'], // prioridade comportamento 4
            ['id' => null, 'ajuste_id' => 15, 'tipo' => 'fjn', 'estado' => 'aprovado']   // prioridade comportamento 1, mas é ajuste!
        ];

        // $justificacoes[0] no array tem 'tipo' => 'ext' (comportamento 'trabalho').
        // $justificacoes[1] é o ajuste com 'tipo' => 'fjn' (comportamento 'fjn').
        // A lógica de EstadoDiaService ordena o array e pega o elemento [0]. A nossa logica de usort modificada deve fazer o ajuste (index 1) subir para index 0.
        // E o comportamento deve ser 'fjn' que cai em falta_justificada_nao_remunerada.
        // Se deu 'servico_externo', é porque o usort não subiu o ajuste para a posição 0 e avaliou o 'ext' (comportamento trabalho) que sem marcacoes e com turno trabalho dá servico_externo.

        $res = $this->service->determinar(1, '2025-10-10', [], $justificacoes, [], ['tipo' => 'trabalho'], $this->map);
        $this->assertEquals('falta_justificada_nao_remunerada', $res['estado']);
        $this->assertEquals('ajuste', $res['origem']);
        $this->assertEquals(15, $res['ajuste_id']);
        $this->assertNull($res['justificacao_id']);
    }

    public function testAjusteFolgaCicloMantemEstadoMasPreencheAjusteId()
    {
        // Ajuste com comportamento 'folga', mas o dia já é 'folga' (turno).
        $justificacoes = [
            ['id' => null, 'ajuste_id' => 77, 'tipo' => 'folga_j', 'estado' => 'aprovado']
        ];

        $res = $this->service->determinar(1, '2025-10-10', [], $justificacoes, [], ['tipo' => 'folga'], $this->map);

        $this->assertEquals('folga_ciclo', $res['estado']); // Base behavior fallback
        $this->assertEquals('ciclo', $res['origem']);
        $this->assertEquals(77, $res['ajuste_id']); // Ajuste ID is preserved
        $this->assertEquals('folga_j', $res['tipo']); // Tipo is preserved
    }

    public function testAjusteSemHorarioMantemEstadoMasPreencheAjusteId()
    {
        // Ajuste com comportamento 'trabalho' sem marcacoes e turno null.
        $justificacoes = [
            ['id' => null, 'ajuste_id' => 88, 'tipo' => 'ext', 'estado' => 'aprovado']
        ];

        $res = $this->service->determinar(1, '2025-10-10', [], $justificacoes, [], null, $this->map);

        $this->assertEquals('sem_horario', $res['estado']);
        $this->assertEquals('ciclo', $res['origem']);
        $this->assertEquals(88, $res['ajuste_id']);
        $this->assertEquals('ext', $res['tipo']);
    }

    public function testAjusteTrabalhadoComPicagens()
    {
        $justificacoes = [
            ['id' => null, 'ajuste_id' => 50, 'tipo' => 'fj', 'estado' => 'aprovado']
        ];
        $marcacoes = [['id' => 1]]; // Picagens existem

        $res = $this->service->determinar(1, '2025-10-10', [], $justificacoes, $marcacoes, ['tipo' => 'trabalho'], $this->map);

        $this->assertEquals('falta_justificada_remunerada', $res['estado']);
        $this->assertEquals('ajuste', $res['origem']);
        $this->assertEquals(50, $res['ajuste_id']); // Identifica que houve um ajuste no dia
        $this->assertEquals('fj', $res['tipo']); // Nome/código mantido
    }
}
