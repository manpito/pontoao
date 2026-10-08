<?php

declare(strict_types=1);

namespace App\Services;

class EstadoDiaService
{
    /**
     * Determina o estado de um dia para um funcionário com base em férias, justificações, marcações e turnos.
     *
     * @param int|string $funcionarioId
     * @param string $data (YYYY-MM-DD)
     * @param array $ferias (array de registos de férias que cobrem o dia, ou vazio)
     * @param array $justificacoes (array de justificações aprovadas que cobrem o dia, ou vazio)
     * @param array $marcacoes (array de marcações do dia, ou vazio)
     * @param array|null $turno (turno do dia, calculado via EscalaService, ou null)
     * @param array $tiposJustificacaoMap (mapa de codigo => comportamento)
     *
     * @return array [ 'estado' => string, 'origem' => string, 'justificacao_id' => int|null, 'tipo' => string|null, 'horas_efectivas' => float ]
     */
    public function determinar(
        $funcionarioId,
        string $data,
        array $ferias,
        array $justificacoes,
        array $marcacoes,
        ?array $turno,
        array $tiposJustificacaoMap
    ): array {
        // 1. Férias
        if (!empty($ferias)) {
            return [
                'estado' => 'ferias',
                'origem' => 'ferias',
                'justificacao_id' => null,
                'tipo' => null,
                'horas_efectivas' => 0.0,
            ];
        }

        // 2. Justificações (estado='aprovado' que cubra a data)
        if (!empty($justificacoes)) {
            // Se houver mais de uma justificação, ordenar por prioridade de comportamento
            // Prioridade: trabalho (4) > folga (3) > falta_justificada_remunerada (2) > falta_justificada_nao_remunerada (1)
            $prioridades = [
                'trabalho' => 4,
                'folga' => 3,
                'falta_justificada_remunerada' => 2,
                'falta_justificada_nao_remunerada' => 1,
            ];

            usort($justificacoes, function ($a, $b) use ($tiposJustificacaoMap, $prioridades) {
                // Compatibility for cases where $j['tipo'] is passed instead of proper justification
                $tipoA = $a['tipo'] ?? 'desconhecido';
                $tipoB = $b['tipo'] ?? 'desconhecido';

                $compA = $tiposJustificacaoMap[$tipoA] ?? 'falta_justificada_nao_remunerada';
                $compB = $tiposJustificacaoMap[$tipoB] ?? 'falta_justificada_nao_remunerada';

                $prioA = $prioridades[$compA] ?? 1;
                $prioB = $prioridades[$compB] ?? 1;

                if ($prioA !== $prioB) {
                    return $prioB <=> $prioA; // Maior prioridade primeiro
                }

                $idA = $a['id'] ?? 0;
                $idB = $b['id'] ?? 0;
                return $idB <=> $idA; // Maior id primeiro (mais recente)
            });

            $justificacao = $justificacoes[0];
            $tipoJust = $justificacao['tipo'] ?? 'desconhecido';
            $comportamento = $tiposJustificacaoMap[$tipoJust] ?? null;

            if ($comportamento === null) {
                error_log("Aviso: Comportamento desconhecido para justificação de tipo '{$tipoJust}'");
                $comportamento = 'falta_justificada_nao_remunerada';
            }

            $horasEfectivas = 0.0;
            $estado = '';

            if ($comportamento === 'trabalho') {
                if (!empty($marcacoes)) {
                    $estado = 'trabalhado';
                } elseif ($turno !== null && $turno['tipo'] !== 'folga') {
                    $estado = 'servico_externo';
                    $horasEfectivas = !empty($turno['horas_efectivas']) ? (float) $turno['horas_efectivas'] : 0.0;
                } elseif ($turno !== null && $turno['tipo'] === 'folga') {
                    $estado = 'folga_ciclo';
                } else {
                    $estado = 'sem_horario';
                }
            } elseif ($comportamento === 'folga') {
                $estado = 'folga_justificada';
            } elseif (in_array($comportamento, ['falta_justificada_remunerada', 'falta_justificada_nao_remunerada'])) {
                if (empty($marcacoes) && $turno !== null && $turno['tipo'] === 'folga') {
                    return [
                        'estado' => 'folga_ciclo',
                        'origem' => 'ciclo',
                        'justificacao_id' => null,
                        'tipo' => null,
                        'horas_efectivas' => 0.0,
                    ];
                }
                $estado = $comportamento;
            } else {
                $estado = 'falta_justificada_nao_remunerada';
            }

            return [
                'estado' => $estado,
                'origem' => 'justificacao',
                'justificacao_id' => isset($justificacao['id']) ? (int)$justificacao['id'] : null,
                'tipo' => $tipoJust,
                'horas_efectivas' => $horasEfectivas,
            ];
        }

        // 3. Marcações no dia
        if (!empty($marcacoes)) {
            return [
                'estado' => 'trabalhado',
                'origem' => 'marcacoes',
                'justificacao_id' => null,
                'tipo' => null,
                'horas_efectivas' => 0.0,
            ];
        }

        // 4. Turno do dia / Horário
        if ($turno === null) {
            return [
                'estado' => 'sem_horario',
                'origem' => 'ciclo',
                'justificacao_id' => null,
                'tipo' => null,
                'horas_efectivas' => 0.0,
            ];
        }

        if ($turno['tipo'] === 'folga') {
            return [
                'estado' => 'folga_ciclo',
                'origem' => 'ciclo',
                'justificacao_id' => null,
                'tipo' => null,
                'horas_efectivas' => 0.0,
            ];
        }

        // Se há turno (trabalho), mas não há picagens, nem férias, nem justificações
        return [
            'estado' => 'falta_injustificada',
            'origem' => 'ciclo',
            'justificacao_id' => null,
            'tipo' => null,
            'horas_efectivas' => 0.0,
        ];
    }
}
