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

        // Atrasos e Saídas antecipadas (regra baseada no horário esperado, igual ao antigo marcacoesDiarias)
        if ($turno && $turno['tipo'] !== 'folga' && $resultado['tipo_presenca'] === 'completo') {
            if ($turno['hora_entrada'] && $resultado['primeira_entrada_str']) {
                [$hP, $mP] = explode(':', $turno['hora_entrada']);
                $minPrevisto = (int)$hP * 60 + (int)$mP;

                [$hR, $mR] = explode(':', $resultado['primeira_entrada_str']);
                $minReal = (int)$hR * 60 + (int)$mR;

                $tolerancia = $turno['tolerancia_entrada_min'] ?? 10;
                $diffEntrada = $minReal - $minPrevisto;

                if ($diffEntrada > $tolerancia) {
                    $resultado['atraso_minutos'] = $diffEntrada - $tolerancia;
                }
            }

            if ($turno['hora_saida'] && $resultado['ultima_saida_str']) {
                $horaPrevistaSaida = substr($turno['hora_saida'], 0, 5);

                if ($turno['atravessa_dia_civil']) {
                    $tsPrevisto = strtotime($dataStr . ' ' . $horaPrevistaSaida . ' +1 day');
                    $tsReal     = strtotime($dataStr . ' ' . $resultado['ultima_saida_str'] . ' +1 day'); // assumption
                    if ($tsPrevisto - $tsReal > 0) {
                        $resultado['saida_antecipada_minutos'] = (int) round(($tsPrevisto - $tsReal) / 60);
                    }
                } else {
                    [$hP, $mP] = explode(':', $horaPrevistaSaida);
                    $minPrevisto = (int)$hP * 60 + (int)$mP;

                    [$hR, $mR] = explode(':', $resultado['ultima_saida_str']);
                    $minReal = (int)$hR * 60 + (int)$mR;

                    if ($minPrevisto - $minReal > 0) {
                        $resultado['saida_antecipada_minutos'] = $minPrevisto - $minReal;
                    }
                }
            }
        }

        return $resultado;
    }
}
