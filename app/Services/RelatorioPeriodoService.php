<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;

class RelatorioPeriodoService
{
    private PDO $db;
    private EscalaService $escalaService;

    public function __construct()
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        $this->db = Database::tenant($sub);
        $this->escalaService = new EscalaService();
    }

    public function calcularResumoPeriodo(array $funcionarioIds, string $dataInicio, string $dataFim): array
    {
        if (empty($funcionarioIds)) {
            return [];
        }

        $inStr = implode(',', array_fill(0, count($funcionarioIds), '?'));

        // 1. Informação dos funcionários
        $stmtF = $this->db->prepare("
            SELECT id, nome_completo, numero_funcionario, vencimento_base_aoa, regime_escala
            FROM funcionarios WHERE id IN ($inStr)
        ");
        $stmtF->execute($funcionarioIds);
        $funcionarios = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        // 2. Marcações do período
        // Estender a query em +1 dia 12:00:00 para capturar saídas de turnos que atravessam o dia civil final
        $paramsM = array_merge($funcionarioIds, [$dataInicio . ' 00:00:00', $dataFim . ' +1 day 12:00:00']);
        $stmtM = $this->db->prepare("
            SELECT funcionario_id, data_hora, tipo
            FROM marcacoes
            WHERE funcionario_id IN ($inStr)
            AND data_hora >= ? AND data_hora <= ?
            ORDER BY data_hora ASC
        ");
        $stmtM->execute($paramsM);
        $todasMarcacoes = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        // Agrupar marcações por funcionário para processamento civil
        $marcacoesPorFuncionario = [];
        foreach ($todasMarcacoes as $m) {
            $marcacoesPorFuncionario[$m['funcionario_id']][] = $m;
        }

        // Justificações de ausência (para saber se o dia é trabalhado por serviço externo)
        $paramsJA = array_merge($funcionarioIds, [$dataFim, $dataInicio]);
        $stmtJA = $this->db->prepare("
            SELECT funcionario_id, data_inicio, data_fim, tipo, estado
            FROM justificacoes_ausencia
            WHERE funcionario_id IN ($inStr)
              AND data_inicio <= ? AND data_fim >= ?
              AND estado = 'aprovado'
        ");
        $stmtJA->execute($paramsJA);
        $todasJustificacoes = $stmtJA->fetchAll(PDO::FETCH_ASSOC);

        $justificacoesPorFunc = [];
        foreach ($todasJustificacoes as $ja) {
            $justificacoesPorFunc[$ja['funcionario_id']][] = $ja;
        }

        $resultados = [];

        foreach ($funcionarios as $func) {
            $funcId = $func['id'];
            $marcFuncBrutas = $marcacoesPorFuncionario[$funcId] ?? [];

            // Usar o agrupamento que suporta a travessia de dias civis
            $marcacoesPorDia = $this->agruparMarcacoesPorDia($marcFuncBrutas, $funcId, $dataInicio, $dataFim);

            $diasTrabalhados = 0;
            $meioDias = 0;
            $horasTrabalhadas = 0.0;
            $horasExtra = 0.0;

            $atual = strtotime($dataInicio);
            $fimTs = strtotime($dataFim);

            $calculoService = new \App\Services\CalculoHorasService();
            $justificacoesFunc = $justificacoesPorFunc[$funcId] ?? [];

            while ($atual <= $fimTs) {
                $dia = date('Y-m-d', $atual);
                $marcacoesDia = $marcacoesPorDia[$dia] ?? [];

                // Verificar se há serviço externo para este dia
                $hasServicoExterno = false;
                foreach ($justificacoesFunc as $ja) {
                    if ($ja['tipo'] === 'servico_externo' && $dia >= $ja['data_inicio'] && $dia <= $ja['data_fim']) {
                        $hasServicoExterno = true;
                        break;
                    }
                }

                $turno = $this->escalaService->calcularTurnoEm($funcId, $dia);
                // Tipo dia fallback (RelatorioPeriodo actual não deduz feriados para H02/H04 separadamente, soma tudo)
                $diaSemana = (int) date('N', $atual);
                $tipoDia = ($diaSemana >= 6) ? 'sabado' : 'util'; // Simplificação, pois o relatório de período agrupa tudo numa só coluna "horas_extra"
                $regimeEscala = $func['regime_escala'] ?? 'normal';

                $resultadoDia = $calculoService->calcularDia($marcacoesDia, $turno, $tipoDia, $regimeEscala, $dia, $hasServicoExterno);

                if ($resultadoDia['tipo_presenca'] === 'meio_dia') {
                    $meioDias += 1;
                    $horasTrabalhadas += $resultadoDia['horas_trabalhadas'];
                } elseif ($resultadoDia['tipo_presenca'] === 'completo' || $resultadoDia['tipo_presenca'] === 'servico_externo') {
                    $diasTrabalhados += 1;
                    $horasTrabalhadas += $resultadoDia['horas_trabalhadas'];
                    $horasExtra += ($resultadoDia['minutos_extra'] + $resultadoDia['minutos_extra_extraordinario']) / 60;
                }

                $atual = strtotime('+1 day', $atual);
            }

            $resultados[] = [
                'funcionario' => $func,
                'resumo' => [
                    'dias_trabalhados' => $diasTrabalhados,
                    'meio_dias' => $meioDias,
                    'total_horas_trabalhadas' => round($horasTrabalhadas, 2),
                    'total_horas_extra' => round($horasExtra, 2)
                ]
            ];
        }

        return $resultados;
    }

    /**
     * Agrupa as marcações puras (brutas) por dia civil, reatribuindo picagens nocturnas ao dia de início do turno.
     * Esta lógica espelha o RelatorioController::marcacoesDiarias() e é crucial para regimes por turnos.
     */
    private function agruparMarcacoesPorDia(array $marcacoesBrutas, int $funcionarioId, string $dataInicio, string $dataFim): array
    {
        $marcPorDia = [];
        foreach ($marcacoesBrutas as $m) {
            $dia = substr($m['data_hora'], 0, 10);
            $marcPorDia[$dia][] = $m;
        }

        $agrupadas = [];
        $atual = strtotime($dataInicio);
        $fimTs = strtotime($dataFim);

        while ($atual <= $fimTs) {
            $dataStr = date('Y-m-d', $atual);
            $mDia = $marcPorDia[$dataStr] ?? [];
            $turno = $this->escalaService->calcularTurnoEm($funcionarioId, $dataStr);

            if ($turno && $turno['tipo'] !== 'folga' && $turno['atravessa_dia_civil']) {
                $horaCorte = '12:00:00';
                $diaSeguinte = date('Y-m-d', strtotime('+1 day', $atual));

                if (isset($marcPorDia[$diaSeguinte])) {
                    foreach ($marcPorDia[$diaSeguinte] as $mNext) {
                        $hora = substr($mNext['data_hora'], 11);
                        if ($hora <= $horaCorte) {
                            $mDia[] = $mNext;
                        }
                    }
                }
            }

            $agrupadas[$dataStr] = $mDia;
            $atual = strtotime('+1 day', $atual);
        }

        return $agrupadas;
    }
}
