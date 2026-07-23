<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class RelatorioPeriodoService
{
    private PDO $pdo;
    private EscalaService $escalaService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->escalaService = new EscalaService($pdo);
    }

    /**
     * Gera o relatório de período para os funcionários activos
     */
    public function gerar(string $dataInicio, string $dataFim): array
    {
        // 1. Obter funcionários activos e os seus horários normais
        $stmtF = $this->pdo->query("
            SELECT f.id, f.nome_completo, f.numero_funcionario,
                   d.nome AS departamento, h.horas_dia AS horas_esperadas_dia
            FROM funcionarios f
            LEFT JOIN departamentos d ON f.departamento_id = d.id
            LEFT JOIN funcionario_horario fh ON fh.funcionario_id = f.id AND fh.data_fim IS NULL
            LEFT JOIN horarios h ON fh.horario_id = h.id
            WHERE f.estado = 'activo'
            ORDER BY f.nome_completo ASC
        ");
        $funcionarios = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        // 2. Obter configurações de horas_extra_entrada_antecipada (se aplicável para cálculo, mas o user disse que descontamos 1h e calculamos horas brutas, extra é o que excede o turno.

        $fimQuery = $dataFim . ' 23:59:59';
        // Ajuste para turnos nocturnos, estendemos a query até meio dia seguinte
        if ($this->periodoTemTurnoNocturnoGlobal($dataInicio, $dataFim)) {
            $fimQuery = date('Y-m-d', strtotime($dataFim . ' +1 day')) . ' 12:00:00';
        }

        // 3. Obter todas as marcações no período
        $stmtM = $this->pdo->prepare("
            SELECT funcionario_id, tipo, data_hora
            FROM marcacoes
            WHERE data_hora BETWEEN :ini AND :fim
            ORDER BY data_hora ASC
        ");
        $stmtM->execute([':ini' => $dataInicio . ' 00:00:00', ':fim' => $fimQuery]);

        $todasMarcacoes = [];
        while ($row = $stmtM->fetch(PDO::FETCH_ASSOC)) {
            $todasMarcacoes[$row['funcionario_id']][] = $row;
        }

        $resultado = [];

        foreach ($funcionarios as $func) {
            $funcId = (int) $func['id'];
            $marcacoesFunc = $todasMarcacoes[$funcId] ?? [];

            $diasTrabalhados = 0;
            $horasTrabalhadas = 0.0;
            $meioDias = 0;
            $horasMeioDia = 0.0;
            $horasExtra = 0.0;

            // Agrupar marcações por dia civil e processar turnos nocturnos
            $marcacoesPorDia = $this->agruparMarcacoesPorDia($marcacoesFunc, $funcId, $dataInicio, $dataFim);

            $atual = strtotime($dataInicio);
            $fimTs = strtotime($dataFim);

            while ($atual <= $fimTs) {
                $dia = date('Y-m-d', $atual);
                $marcacoesDia = $marcacoesPorDia[$dia] ?? [];

                if (count($marcacoesDia) === 0) {
                    $atual = strtotime('+1 day', $atual);
                    continue; // Dia sem marcações
                }

                if (count($marcacoesDia) === 1) {
                    // Meio Dia
                    $meioDias += 1;
                    $horasTrabalhadas += 4.0;
                    $horasMeioDia += 4.0;
                } else {
                    // Dia Completo
                    // Encontrar primeira entrada e última saída
                    $entrada = null;
                    $saida = null;
                    foreach ($marcacoesDia as $m) {
                        $ts = strtotime($m['data_hora']);
                        if ($m['tipo'] === 'entrada' && ($entrada === null || $ts < $entrada)) {
                            $entrada = $ts;
                        }
                        if ($m['tipo'] === 'saida' && ($saida === null || $ts > $saida)) {
                            $saida = $ts;
                        }
                    }

                    if ($entrada !== null && $saida !== null) {
                        $brutoHoras = ($saida - $entrada) / 3600;
                        // Desconto fixo de 1 hora
                        $liquidoHoras = $brutoHoras - 1.0;

                        // Se o líquido for <= 0 após almoço, assume-se como 0 para não descontar horas, mas conta como dia trabalhado com 0h.
                        $liquidoHorasAjustado = max(0, $liquidoHoras);

                        $diasTrabalhados += 1;
                        $horasTrabalhadas += $liquidoHorasAjustado;

                        // Calcular Horas Extra
                        $horasEsperadas = $this->getHorasEsperadasDia($funcId, $dia, (float) ($func['horas_esperadas_dia'] ?? 8.0));
                        if ($liquidoHorasAjustado > $horasEsperadas) {
                            $horasExtra += ($liquidoHorasAjustado - $horasEsperadas);
                        }
                    }
                }

                $atual = strtotime('+1 day', $atual);
            }

            // Converter totais para formato adequado (2 casas decimais) e somar tudo no total do utilizador
            $resultado[] = [
                'funcionario_id' => $funcId,
                'funcionario_nome' => $func['nome_completo'],
                'dias_trabalhados' => $diasTrabalhados,
                'horas_trabalhadas' => round($horasTrabalhadas, 2),
                'meio_dias' => $meioDias,
                'horas_meio_dia' => round($horasMeioDia, 2),
                'horas_extra' => round($horasExtra, 2)
            ];
        }

        return $resultado;
    }

    private function getHorasEsperadasDia(int $funcId, string $dia, float $fallbackHoras): float
    {
        $turno = $this->escalaService->calcularTurnoEm($funcId, $dia);

        if ($turno && $turno['tipo'] !== 'folga') {
            if ($turno['horas_efectivas'] !== null) {
                return (float) $turno['horas_efectivas'];
            }
        } elseif ($turno && $turno['tipo'] === 'folga') {
            return 0.0;
        }

        // Se não tem escala, tenta ir buscar o horário normal do dia da semana
        $dow = date('N', strtotime($dia));
        $stmt = $this->pdo->prepare("
            SELECT ht.dia_folga, h.horas_dia
            FROM funcionario_horario fh
            JOIN horarios h ON fh.horario_id = h.id
            JOIN horario_turnos ht ON ht.horario_id = h.id AND ht.dia_semana = :dow
            WHERE fh.funcionario_id = :fid
              AND :data BETWEEN fh.data_inicio AND COALESCE(fh.data_fim, '9999-12-31')
            LIMIT 1
        ");
        $stmt->execute([':dow' => $dow, ':fid' => $funcId, ':data' => $dia]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if ($row['dia_folga'] == 1) {
                return 0.0;
            }
            if ($row['horas_dia']) {
                return (float) $row['horas_dia'];
            }
        }

        return $fallbackHoras;
    }

    private function agruparMarcacoesPorDia(array $marcacoes, int $funcId, string $inicioStr, string $fimStr): array
    {
        $dias = [];
        $inicioTs = strtotime($inicioStr);
        $fimTs = strtotime($fimStr);

        foreach ($marcacoes as $m) {
            $ts = strtotime($m['data_hora']);
            $diaStr = substr($m['data_hora'], 0, 10);
            $horaStr = substr($m['data_hora'], 11, 8);

            // Reatribuição para turnos nocturnos
            if ($horaStr >= '00:00:00' && $horaStr <= '12:00:00') {
                $diaAnteriorStr = date('Y-m-d', strtotime($diaStr . ' -1 day'));
                $turnoAnterior = $this->escalaService->calcularTurnoEm($funcId, $diaAnteriorStr);
                if ($turnoAnterior && $turnoAnterior['atravessa_dia_civil']) {
                    $diaStr = $diaAnteriorStr;
                }
            }

            // Só guardar se a marcação for de um dia que cai no período (pode haver reatribuições para dias de fora, não ignoramos, mas depois no processamento não contamos)
            $dias[$diaStr][] = $m;
        }

        return $dias;
    }

    private function periodoTemTurnoNocturnoGlobal(string $dataInicio, string $dataFim): bool
    {
        // Simplificação: apenas vemos se algum funcionário teve turno nocturno na escala neste período
        // Caso contrário, é overkill para cada query.
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM escalas e
            JOIN turnos t ON (
                t.id IN (SELECT turno_id FROM rotacoes r WHERE r.escala_id = e.id)
                OR t.id IN (SELECT turno_id FROM escala_excepcoes exc WHERE exc.data BETWEEN :ini AND :fim)
            )
            WHERE t.atravessa_dia_civil = 1
            LIMIT 1
        ");
        $stmt->execute([':ini' => $dataInicio, ':fim' => $dataFim]);
        return (bool) $stmt->fetchColumn();
    }
}
