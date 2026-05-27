<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * RelatorioController — Relatórios de assiduidade e horas trabalhadas
 *
 * Lógica conforme LGT Lei 7/15:
 * - Art.º 96.º: limite de 8h/dia e 44h/semana
 * - Art.º 100.º: intervalo mínimo de 30min obrigatório
 * - Art.º 215.º: 22 dias úteis de férias/ano
 * - Horas extra = horas efectivas - horas esperadas (quando positivo)
 */
class RelatorioController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/relatorios/assiduidade
     * Params: data_inicio, data_fim, funcionario_id (opcional), departamento_id (opcional)
     *
     * Devolve por funcionário e por dia:
     * - presente / ausente / feriado / fim_semana
     * - tipo de ausência (justificada / injustificada)
     * - marcações do dia
     */
    public function assiduidade(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params    = $request->getQueryParams();
        $dataInicio = $params['data_inicio'] ?? date('Y-m-01');
        $dataFim    = $params['data_fim']    ?? date('Y-m-t');
        $funcId     = !empty($params['funcionario_id'])   ? (int) $params['funcionario_id']   : null;
        $depId      = !empty($params['departamento_id'])  ? (int) $params['departamento_id']  : null;
        $numFunc    = !empty($params['numero'])            ? $params['numero']                 : null;
        $nomeSearch = !empty($params['search'])            ? $params['search']                 : null;

        if ($dataFim < $dataInicio) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'data_fim não pode ser anterior a data_inicio.']);
        }

        $db = $this->db();

        // 1. Buscar funcionários
        $whereFuncs = ["f.estado = 'activo'"];
        $bindFuncs  = [];
        // Filtro supervisor: apenas a sua equipa
        if ($perfil === 'supervisor' && !empty($user->funcionario_id)) {
            $whereFuncs[] = '(f.supervisor_id = :sid OR f.id = :sid)';
            $bindFuncs[':sid'] = (int) $user->funcionario_id;
        }

        if ($funcId)     { $whereFuncs[] = 'f.id = :fid'; $bindFuncs[':fid'] = $funcId; }
        if ($depId)      { $whereFuncs[] = 'f.departamento_id = :did'; $bindFuncs[':did'] = $depId; }
        if ($numFunc)    { $whereFuncs[] = 'f.numero_funcionario LIKE :num'; $bindFuncs[':num'] = '%'.$numFunc.'%'; }
        if ($nomeSearch) { $whereFuncs[] = 'f.nome_completo LIKE :nome'; $bindFuncs[':nome'] = '%'.$nomeSearch.'%'; }

        $stmtF = $db->prepare("
            SELECT f.id, f.nome_completo, f.numero_funcionario, f.data_admissao,
                   d.nome AS departamento,
                   h.horas_dia AS horas_esperadas_dia,
                   h.tolerancia_entrada_min
            FROM funcionarios f
            LEFT JOIN departamentos d ON f.departamento_id = d.id
            LEFT JOIN funcionario_horario fh ON fh.funcionario_id = f.id AND fh.data_fim IS NULL
            LEFT JOIN horarios h ON fh.horario_id = h.id
            WHERE " . implode(' AND ', $whereFuncs) . "
            ORDER BY f.nome_completo ASC
        ");
        $stmtF->execute($bindFuncs);
        $funcionarios = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        if (empty($funcionarios)) {
            return $this->json(200, ['dados' => [], 'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim]]);
        }

        // 2. Buscar feriados no período
        $feriados = $this->getFeriados($db, $dataInicio, $dataFim);

        // 3. Buscar marcações no período (todos os funcionários de uma vez)
        $ids   = array_column($funcionarios, 'id');
        $inStr = implode(',', $ids);
        $stmtM = $db->query("
            SELECT funcionario_id, tipo, data_hora, origem, editada
            FROM marcacoes
            WHERE funcionario_id IN ({$inStr})
              AND data_hora BETWEEN '{$dataInicio} 00:00:00' AND '{$dataFim} 23:59:59'
            ORDER BY data_hora ASC
        ");
        $todasMarcacoes = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        // 4. Buscar justificações no período
        $stmtJ = $db->query("
            SELECT funcionario_id, data_inicio, data_fim, tipo, estado
            FROM justificacoes
            WHERE funcionario_id IN ({$inStr})
              AND data_inicio <= '{$dataFim}' AND data_fim >= '{$dataInicio}'
              AND estado = 'aprovada'
        ");
        $justificacoes = $stmtJ->fetchAll(PDO::FETCH_ASSOC);

        // 5. Calcular por funcionário
        $resultado = [];

        foreach ($funcionarios as $func) {
            $marcFunc = array_filter($todasMarcacoes, fn($m) => $m['funcionario_id'] == $func['id']);
            $justFunc = array_filter($justificacoes, fn($j) => $j['funcionario_id'] == $func['id']);

            // Agrupar marcações por dia
            $marcPorDia = [];
            foreach ($marcFunc as $m) {
                $dia = substr($m['data_hora'], 0, 10);
                $marcPorDia[$dia][] = $m;
            }

            // Iterar dias do período
            $dias         = [];
            $totalPresente = 0;
            $totalAusente  = 0;
            $totalJustif   = 0;
            $totalFeriado  = 0;
            $totalFimSem   = 0;

            $atual = strtotime($dataInicio);
            $fim   = strtotime($dataFim);

            while ($atual <= $fim) {
                $dataStr   = date('Y-m-d', $atual);
                $diaSemana = (int) date('N', $atual); // 1=Seg, 7=Dom

                // Só contar a partir da admissão
                if ($dataStr < $func['data_admissao']) {
                    $atual = strtotime('+1 day', $atual);
                    continue;
                }

                $diaInfo = [
                    'data'      => $dataStr,
                    'dia_semana' => $diaSemana,
                    'tipo'      => '',
                    'marcacoes' => $marcPorDia[$dataStr] ?? [],
                ];

                if ($diaSemana >= 6) {
                    $diaInfo['tipo'] = 'fim_semana';
                    $totalFimSem++;
                } elseif (isset($feriados[$dataStr])) {
                    $diaInfo['tipo']    = 'feriado';
                    $diaInfo['feriado'] = $feriados[$dataStr];
                    $totalFeriado++;
                } elseif (!empty($marcPorDia[$dataStr])) {
                    $diaInfo['tipo'] = 'presente';
                    $totalPresente++;
                } else {
                    // Verificar justificação
                    $justificado = false;
                    foreach ($justFunc as $j) {
                        if ($dataStr >= $j['data_inicio'] && $dataStr <= $j['data_fim']) {
                            $justificado   = true;
                            $diaInfo['justificacao'] = $j['tipo'];
                            break;
                        }
                    }
                    $diaInfo['tipo'] = $justificado ? 'justificado' : 'ausente';
                    if ($justificado) $totalJustif++; else $totalAusente++;
                }

                $dias[] = $diaInfo;
                $atual  = strtotime('+1 day', $atual);
            }

            $diasUteis = $totalPresente + $totalAusente + $totalJustif;

            $resultado[] = [
                'funcionario'         => [
                    'id'                  => $func['id'],
                    'nome'                => $func['nome_completo'],
                    'numero'              => $func['numero_funcionario'],
                    'departamento'        => $func['departamento'],
                ],
                'resumo' => [
                    'dias_uteis'          => $diasUteis,
                    'dias_presente'       => $totalPresente,
                    'dias_ausente'        => $totalAusente,
                    'dias_justificados'   => $totalJustif,
                    'dias_feriado'        => $totalFeriado,
                    'taxa_presenca'       => $diasUteis > 0 ? round($totalPresente / $diasUteis * 100, 1) : 0,
                ],
                'dias' => $dias,
            ];
        }

        return $this->json(200, [
            'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim],
            'total_funcionarios' => count($resultado),
            'dados'  => $resultado,
            'gerado_em' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * GET /api/relatorios/horas
     * Params: data_inicio, data_fim, funcionario_id (opcional)
     *
     * Calcula por funcionário:
     * - horas efectivas trabalhadas (entrada - saída - intervalos)
     * - horas esperadas (horário × dias presentes)
     * - horas extra (LGT Art.º 96.º)
     * - minutos de atraso acumulados
     * - minutos de saída antecipada
     */
    public function horas(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params     = $request->getQueryParams();
        $dataInicio = $params['data_inicio'] ?? date('Y-m-01');
        $dataFim    = $params['data_fim']    ?? date('Y-m-t');
        $funcId     = !empty($params['funcionario_id']) ? (int) $params['funcionario_id'] : null;

        $db = $this->db();

        $whereFuncs = ["f.estado = 'activo'"];
        $bindFuncs  = [];
        $numFuncH    = !empty($params['numero'])  ? $params['numero']  : null;
        $nomeSearchH = !empty($params['search'])  ? $params['search']  : null;
        // Filtro supervisor: apenas a sua equipa
        if ($perfil === 'supervisor' && !empty($user->funcionario_id)) {
            $whereFuncs[] = '(f.supervisor_id = :sid OR f.id = :sid)';
            $bindFuncs[':sid'] = (int) $user->funcionario_id;
        }

        if ($funcId)      { $whereFuncs[] = 'f.id = :fid'; $bindFuncs[':fid'] = $funcId; }
        if (!empty($params['departamento_id'])) { $whereFuncs[] = 'f.departamento_id = :did'; $bindFuncs[':did'] = (int)$params['departamento_id']; }
        if ($numFuncH)    { $whereFuncs[] = 'f.numero_funcionario LIKE :num'; $bindFuncs[':num'] = '%'.$numFuncH.'%'; }
        if ($nomeSearchH) { $whereFuncs[] = 'f.nome_completo LIKE :nome'; $bindFuncs[':nome'] = '%'.$nomeSearchH.'%'; }

        $stmtF = $db->prepare("
            SELECT f.id, f.nome_completo, f.numero_funcionario,
                   d.nome AS departamento,
                   h.horas_dia AS horas_esperadas_dia,
                   h.horas_semana AS horas_esperadas_semana,
                   h.tolerancia_entrada_min,
                   ht.hora_entrada AS hora_entrada_padrao,
                   ht.hora_saida   AS hora_saida_padrao
            FROM funcionarios f
            LEFT JOIN departamentos d ON f.departamento_id = d.id
            LEFT JOIN funcionario_horario fh ON fh.funcionario_id = f.id AND fh.data_fim IS NULL
            LEFT JOIN horarios h ON fh.horario_id = h.id
            LEFT JOIN horario_turnos ht ON ht.horario_id = h.id AND ht.dia_semana = 1
            WHERE " . implode(' AND ', $whereFuncs) . "
            ORDER BY f.nome_completo ASC
        ");
        $stmtF->execute($bindFuncs);
        $funcionarios = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        if (empty($funcionarios)) {
            return $this->json(200, ['dados' => [], 'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim]]);
        }

        $feriados = $this->getFeriados($db, $dataInicio, $dataFim);
        $ids      = array_column($funcionarios, 'id');
        $inStr    = implode(',', $ids);

        $stmtM = $db->query("
            SELECT funcionario_id, tipo, data_hora
            FROM marcacoes
            WHERE funcionario_id IN ({$inStr})
              AND data_hora BETWEEN '{$dataInicio} 00:00:00' AND '{$dataFim} 23:59:59'
            ORDER BY funcionario_id, data_hora ASC
        ");
        $todasMarcacoes = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        $resultado = [];

        foreach ($funcionarios as $func) {
            $marcFunc   = array_values(array_filter($todasMarcacoes, fn($m) => $m['funcionario_id'] == $func['id']));
            $marcPorDia = [];
            foreach ($marcFunc as $m) {
                $dia = substr($m['data_hora'], 0, 10);
                $marcPorDia[$dia][] = $m;
            }

            $horasEsperadasDia = (float) ($func['horas_esperadas_dia'] ?? 8);
            $tolerancia        = (int)   ($func['tolerancia_entrada_min'] ?? 10);
            $horaPadraoEntrada = $func['hora_entrada_padrao'] ?? '08:00:00';
            $horaPadraoSaida   = $func['hora_saida_padrao']   ?? '17:00:00';

            $totalMinutosEfetivos = 0;
            $totalMinutosEsperados = 0;
            $totalMinutosAtraso    = 0;
            $totalMinutosSaidaAnt  = 0;
            $totalDiasPresente     = 0;
            $detalhesDia           = [];

            foreach ($marcPorDia as $dia => $marcacoes) {
                $diaSemana = (int) date('N', strtotime($dia));
                if ($diaSemana >= 6 || isset($feriados[$dia])) continue;

                $entrada         = null;
                $saida           = null;
                $minutosIntervalo = 0;
                $inicioIntervalo  = null;

                foreach ($marcacoes as $m) {
                    $ts = strtotime($m['data_hora']);
                    switch ($m['tipo']) {
                        case 'entrada':
                            $entrada = $ts;
                            break;
                        case 'saida':
                            $saida = $ts;
                            break;
                        case 'inicio_intervalo':
                            $inicioIntervalo = $ts;
                            break;
                        case 'fim_intervalo':
                            if ($inicioIntervalo) {
                                $minutosIntervalo += (int) round(($ts - $inicioIntervalo) / 60);
                                $inicioIntervalo   = null;
                            }
                            break;
                    }
                }

                if (!$entrada) continue;

                $saidaEfetiva = $saida ?? ($entrada + ($horasEsperadasDia * 3600));
                $minutosTrabalhados = (int) round(($saidaEfetiva - $entrada) / 60) - $minutosIntervalo;
                $minutosEsperados   = (int) ($horasEsperadasDia * 60);

                // Calcular atraso (tolerância incluída)
                $entradaPrevista = strtotime($dia . ' ' . $horaPadraoEntrada);
                $atrasoMin = max(0, (int) round(($entrada - $entradaPrevista) / 60) - $tolerancia);

                // Calcular saída antecipada
                $saidaPrevista  = strtotime($dia . ' ' . $horaPadraoSaida);
                $saidaAntMin    = $saida ? max(0, (int) round(($saidaPrevista - $saida) / 60)) : 0;

                $totalMinutosEfetivos  += $minutosTrabalhados;
                $totalMinutosEsperados += $minutosEsperados;
                $totalMinutosAtraso    += $atrasoMin;
                $totalMinutosSaidaAnt  += $saidaAntMin;
                $totalDiasPresente++;

                $detalhesDia[] = [
                    'data'                => $dia,
                    'entrada'             => $entrada ? date('H:i', $entrada) : null,
                    'saida'               => $saida   ? date('H:i', $saida)   : null,
                    'minutos_intervalo'   => $minutosIntervalo,
                    'minutos_trabalhados' => $minutosTrabalhados,
                    'minutos_esperados'   => $minutosEsperados,
                    'minutos_extra'       => max(0, $minutosTrabalhados - $minutosEsperados),
                    'minutos_deficit'     => max(0, $minutosEsperados - $minutosTrabalhados),
                    'atraso_min'          => $atrasoMin,
                    'saida_antecipada_min' => $saidaAntMin,
                ];
            }

            $minutosExtra  = max(0, $totalMinutosEfetivos - $totalMinutosEsperados);
            $minutosDeficit = max(0, $totalMinutosEsperados - $totalMinutosEfetivos);

            $resultado[] = [
                'funcionario' => [
                    'id'          => $func['id'],
                    'nome'        => $func['nome_completo'],
                    'numero'      => $func['numero_funcionario'],
                    'departamento' => $func['departamento'],
                ],
                'resumo' => [
                    'dias_presentes'          => $totalDiasPresente,
                    'horas_efectivas'         => round($totalMinutosEfetivos / 60, 2),
                    'horas_esperadas'         => round($totalMinutosEsperados / 60, 2),
                    'horas_extra'             => round($minutosExtra / 60, 2),
                    'horas_deficit'           => round($minutosDeficit / 60, 2),
                    'minutos_atraso_total'    => $totalMinutosAtraso,
                    'minutos_saida_ant_total' => $totalMinutosSaidaAnt,
                    'saldo_horas'             => round(($totalMinutosEfetivos - $totalMinutosEsperados) / 60, 2),
                ],
                'dias' => $detalhesDia,
            ];
        }

        return $this->json(200, [
            'periodo'            => ['inicio' => $dataInicio, 'fim' => $dataFim],
            'total_funcionarios' => count($resultado),
            'dados'              => $resultado,
            'gerado_em'          => date('Y-m-d H:i:s'),
            'nota_legal'         => 'Horas extra calculadas conforme LGT Lei 7/15, Art.º 96.º (limite 8h/dia, 44h/semana).',
        ]);
    }

    /**
     * Devolve feriados do período como array indexado por data
     */
    private function getFeriados(PDO $db, string $inicio, string $fim): array
    {
        $stmt = $db->prepare("
            SELECT data, nome, meio_dia
            FROM feriados
            WHERE data BETWEEN :ini AND :fim
               OR (recorrente = 1 AND DATE_FORMAT(data, '%m-%d') BETWEEN DATE_FORMAT(:ini2, '%m-%d') AND DATE_FORMAT(:fim2, '%m-%d'))
        ");
        $stmt->execute([':ini' => $inicio, ':fim' => $fim, ':ini2' => $inicio, ':fim2' => $fim]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $result[$f['data']] = ['nome' => $f['nome'], 'meio_dia' => (bool) $f['meio_dia']];
        }
        return $result;
    }

    /**
     * GET /api/relatorios/assiduidade/exportar?formato=csv&...
     * GET /api/relatorios/horas/exportar?formato=csv&...
     */
    public function exportarAssiduidade(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->exportar($request, $response, 'assiduidade');
    }

    public function exportarHoras(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->exportar($request, $response, 'horas');
    }

    private function exportar(ServerRequestInterface $request, ResponseInterface $response, string $tipo): ResponseInterface
    {
        $formato = $request->getQueryParams()['formato'] ?? 'csv';

        // Obter dados usando o método existente
        $dadosResponse = $tipo === 'assiduidade'
            ? $this->assiduidade($request, new Response())
            : $this->horas($request, new Response());

        $body = json_decode((string) $dadosResponse->getBody(), true);
        $dados = $body['dados'] ?? [];
        $periodo = $body['periodo'] ?? [];

        $filename = "relatorio_{$tipo}_{$periodo['inicio']}_{$periodo['fim']}";

        if ($formato === 'csv') {
            return $this->exportarCSV($dados, $tipo, $filename, $response);
        }

        return $dadosResponse;
    }

    private function exportarCSV(array $dados, string $tipo, string $filename, ResponseInterface $response): ResponseInterface
    {
        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF"); // BOM UTF-8

        if ($tipo === 'assiduidade') {
            fputcsv($output, ['Nº', 'Nome', 'Departamento', 'Dias Úteis', 'Presentes', 'Ausentes', 'Justificados', 'Feriados', 'Taxa Presença (%)'], ';');
            foreach ($dados as $r) {
                $s = $r['resumo'];
                fputcsv($output, [
                    $r['funcionario']['numero'],
                    $r['funcionario']['nome'],
                    $r['funcionario']['departamento'] ?? '',
                    $s['dias_uteis'],
                    $s['dias_presente'],
                    $s['dias_ausente'],
                    $s['dias_justificados'],
                    $s['dias_feriado'],
                    $s['taxa_presenca'],
                ], ';');
            }
        } else {
            fputcsv($output, ['Nº', 'Nome', 'Departamento', 'H. Efectivas', 'H. Esperadas', 'H. Extra', 'H. Défice', 'Atrasos (min)', 'Saldo (h)'], ';');
            foreach ($dados as $r) {
                $s = $r['resumo'];
                fputcsv($output, [
                    $r['funcionario']['numero'],
                    $r['funcionario']['nome'],
                    $r['funcionario']['departamento'] ?? '',
                    $s['horas_efectivas'],
                    $s['horas_esperadas'],
                    $s['horas_extra'],
                    $s['horas_deficit'],
                    $s['minutos_atraso_total'],
                    $s['saldo_horas'],
                ], ';');
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $resp = new Response(200);
        $resp->getBody()->write($csv);
        return $resp
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}.csv\"")
            ->withHeader('Cache-Control', 'no-cache');
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
