<?php

declare(strict_types=1);

namespace App\Services;

class CalculoHorasService
{
    /**
     * Calcula as horas de um dia para um funcionário baseado nas regras autoritativas:
     * - Dia completo: (saída - entrada) - 1h fixa de almoço.
     * - Meio dia: 4h fixas independentemente de ser manhã ou tarde.
     * - Dia sem picagem: 0h.
     * - Horas extra: liquido - esperado (apenas em dia completo).
     */
    public function calcularDia(
        array $marcacoes,
        ?array $turno,
        string $tipoDia, // 'util', 'sabado', 'domingo', 'feriado'
        string $regimeEscala, // 'normal', 'turnos'
        string $dataStr
    ): array {
        // Inicializar o resultado
        $resultado = [
            'tipo_presenca'                => 'ausente', // completo, meio_dia, ausente
            'horas_trabalhadas'            => 0.0,
            'minutos_totais'               => 0, // para manter compatibilidade com algumas chamadas
            'minutos_extra'                => 0,
            'minutos_extra_extraordinario' => 0,
            'atraso_minutos'               => 0,
            'saida_antecipada_minutos'     => 0,
            'primeira_entrada_ts'          => null,
            'ultima_saida_ts'              => null,
            'primeira_entrada_str'         => null,
            'ultima_saida_str'             => null,
        ];

        if (count($marcacoes) === 0) {
            return $resultado;
        }

        // Encontrar a primeira entrada e a última saída
        $primeiraEntradaTs = null;
        $ultimaSaidaTs     = null;
        $todasEntradas     = [];
        $todasSaidas       = [];

        foreach ($marcacoes as $m) {
            $ts = strtotime($m['data_hora']);
            if ($m['tipo'] === 'entrada') {
                $todasEntradas[] = $ts;
            } elseif ($m['tipo'] === 'saida') {
                $todasSaidas[] = $ts;
            }
        }

        if (!empty($todasEntradas)) {
            $primeiraEntradaTs = min($todasEntradas);
            $resultado['primeira_entrada_ts'] = $primeiraEntradaTs;
            $resultado['primeira_entrada_str'] = date('H:i', $primeiraEntradaTs);
        }
        if (!empty($todasSaidas)) {
            $ultimaSaidaTs = max($todasSaidas);
            $resultado['ultima_saida_ts'] = $ultimaSaidaTs;
            $resultado['ultima_saida_str'] = date('H:i', $ultimaSaidaTs);
        }

        // Regra: se só tem entrada e não tem saída, ou vice-versa, é MEIO DIA
        if (count($todasEntradas) > 0 && count($todasSaidas) === 0) {
            $resultado['tipo_presenca'] = 'meio_dia';
        } elseif (count($todasSaidas) > 0 && count($todasEntradas) === 0) {
            $resultado['tipo_presenca'] = 'meio_dia';
        } elseif (count($todasEntradas) > 0 && count($todasSaidas) > 0) {
            $resultado['tipo_presenca'] = 'completo';
        }

        // Horas efetivas esperadas pelo turno
        $minutosEsperados = 0;
        if ($turno && $turno['tipo'] !== 'folga' && $turno['horas_efectivas']) {
            $minutosEsperados = (int) round((float)$turno['horas_efectivas'] * 60);
        }

        if ($resultado['tipo_presenca'] === 'meio_dia') {
            // Regra autoritativa: Meio dia vale sempre 4 horas fixas, 0 horas extra.
            $resultado['horas_trabalhadas'] = 4.0;
            $resultado['minutos_totais']    = 240;
            // Atrasos não se aplicam ou são complexos, mas não geram horas extra.

        } elseif ($resultado['tipo_presenca'] === 'completo') {
            // Regra autoritativa: (saída - entrada) - 1h de almoço fixa
            $diffBruto = $ultimaSaidaTs - $primeiraEntradaTs;

            // Tratamento de travessia civil manual baseada em turno atravessa dia civil, apenas se diffBruto negativo e for turno.
            if ($turno && $turno['atravessa_dia_civil'] && $diffBruto < 0) {
                $diffBruto += 86400;
            }

            $minutosBruto = (int) round($diffBruto / 60);
            $minutosLiquido = max(0, $minutosBruto - 60); // Desconto 1h fixa

            $resultado['minutos_totais'] = $minutosLiquido;
            $resultado['horas_trabalhadas'] = round($minutosLiquido / 60, 2);

            // Cálculo de Extras
            if ($minutosLiquido > 0) {
                if ($tipoDia === 'util') {
                    $resultado['minutos_extra'] = max(0, $minutosLiquido - $minutosEsperados);
                } elseif (in_array($tipoDia, ['sabado', 'domingo', 'feriado'])) {
                    if ($regimeEscala === 'turnos') {
                        if ($turno && $turno['tipo'] !== 'folga') {
                            $resultado['minutos_extra'] = max(0, $minutosLiquido - $minutosEsperados);
                        } else {
                            $resultado['minutos_extra_extraordinario'] = $minutosLiquido;
                        }
                    } else {
                        // regime normal: fds/feriado = tudo extraordinário
                        $resultado['minutos_extra_extraordinario'] = $minutosLiquido;
                    }
                }
            }
        }

        // Atrasos e Saídas antecipadas (regra baseada no horário esperado, usando timestamp real e não string truncada a minutos)
        if ($turno && $turno['tipo'] !== 'folga' && $resultado['tipo_presenca'] === 'completo') {
            if ($turno['hora_entrada'] && $resultado['primeira_entrada_ts']) {
                $tsPrevistoEntrada = strtotime($dataStr . ' ' . $turno['hora_entrada']);
                $tsRealEntrada = $resultado['primeira_entrada_ts'];

                $tolerancia = $turno['tolerancia_entrada_min'] ?? 10;
                $diffEntradaSegundos = $tsRealEntrada - $tsPrevistoEntrada;
                $diffEntradaMinutos = (int) round($diffEntradaSegundos / 60);

                if ($diffEntradaMinutos > $tolerancia) {
                    $resultado['atraso_minutos'] = $diffEntradaMinutos - $tolerancia;
                }
            }

            if ($turno['hora_saida'] && $resultado['ultima_saida_ts']) {
                if ($turno['atravessa_dia_civil']) {
                    $tsPrevistoSaida = strtotime($dataStr . ' ' . $turno['hora_saida'] . ' +1 day');
                } else {
                    $tsPrevistoSaida = strtotime($dataStr . ' ' . $turno['hora_saida']);
                }

                $tsRealSaida = $resultado['ultima_saida_ts'];

                if ($tsPrevistoSaida - $tsRealSaida > 0) {
                    $resultado['saida_antecipada_minutos'] = (int) round(($tsPrevistoSaida - $tsRealSaida) / 60);
                }
            }
        }

        return $resultado;
    }
}
