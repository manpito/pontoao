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
 * ExportacaoController — Exportação de dados para sistemas externos
 *
 * GET /api/exportacao/primavera   — CSV compatível com Primavera Professional V10
 * GET /api/exportacao/funcionarios — CSV/template para importação
 */
class ExportacaoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/exportacao/primavera?mes=2026-04
     *
     * Gera CSV compatível com o módulo de Processamento Salarial
     * do Primavera Professional V10 (Angola).
     *
     * Colunas: Número, Nome, NIF, NISS, Vencimento Base,
     *          Dias Trabalhados, Horas Extra, Faltas Injustificadas,
     *          Faltas Justificadas, Num Dependentes, Tipo Contrato
     */
    public function primavera(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params     = $request->getQueryParams();
        $mes        = $params['mes'] ?? date('Y-m');
        $db         = $this->db();

        // Obter configuração do ciclo de pagamento
        $stmtCfg = $db->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('periodo_dia_inicio','periodo_dia_fim')");
        $config = [];
        while ($row = $stmtCfg->fetch(PDO::FETCH_ASSOC)) {
            $config[$row['chave']] = $row['valor'];
        }

        $diaInicio = (int) ($config['periodo_dia_inicio'] ?? 1);
        $diaFim = (int) ($config['periodo_dia_fim'] ?? 31);
        $ano = (int) substr($mes, 0, 4);
        $mesInt = (int) substr($mes, 5, 2);

        if ($diaInicio > 1) {
            $mesAnterior = $mesInt === 1 ? 12 : $mesInt - 1;
            $anoAnterior = $mesInt === 1 ? $ano - 1 : $ano;
            $ultimoDia = cal_days_in_month(CAL_GREGORIAN, $mesAnterior, $anoAnterior);
            $diaReal = min($diaInicio, $ultimoDia);
            $dataInicio = sprintf('%04d-%02d-%02d', $anoAnterior, $mesAnterior, $diaReal);
        } else {
            $dataInicio = sprintf('%04d-%02d-%02d', $ano, $mesInt, 1);
        }

        $ultimoDiaMesFim = cal_days_in_month(CAL_GREGORIAN, $mesInt, $ano);
        if ($diaFim >= $ultimoDiaMesFim || $diaFim === 31) {
            $dataFim = sprintf('%04d-%02d-%02d', $ano, $mesInt, $ultimoDiaMesFim);
        } else {
            $dataFim = sprintf('%04d-%02d-%02d', $ano, $mesInt, $diaFim);
        }

        // 2.2 — Buscar funcionários activos
        $idsParam = $params['funcionario_ids'] ?? '';
        $depId    = $params['departamento_id'] ?? '';

        $where = ["f.estado = 'activo'"];
        $bind  = [];

        if (!empty($idsParam)) {
            $ids = array_filter(array_map('intval', explode(',', $idsParam)));
            if (!empty($ids)) {
                $inStr = implode(',', $ids);
                $where[] = "f.id IN ({$inStr})";
            }
        } elseif (!empty($depId)) {
            $where[] = 'f.departamento_id = :dep_id';
            $bind[':dep_id'] = (int) $depId;
        }

        $whereStr = implode(' AND ', $where);
        $stmtF = $db->prepare("
            SELECT f.id, f.numero_funcionario,
                   (SELECT e.regime
                    FROM escalas e
                    JOIN funcionario_escala fe ON fe.escala_id = e.id
                    WHERE fe.funcionario_id = f.id AND (fe.data_fim IS NULL OR fe.data_fim >= CURDATE())
                    LIMIT 1) as regime_escala
            FROM funcionarios f
            WHERE {$whereStr}
            ORDER BY f.numero_funcionario ASC
        ");
        $stmtF->execute($bind);
        $funcionarios = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        if (empty($funcionarios)) {
            $response->getBody()->write("");
            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
                ->withHeader('Content-Disposition', 'attachment; filename="exportacao_primavera_' . $mes . '.txt"');
        }

        $ids   = array_column($funcionarios, 'id');
        $inStr = implode(',', array_map('intval', $ids));

        // 2.3 — Buscar feriados do período
        $feriados = [];
        $stmtFer = $db->prepare("SELECT data FROM feriados WHERE data BETWEEN :ini AND :fim");
        $stmtFer->execute([':ini' => $dataInicio, ':fim' => $dataFim]);
        foreach ($stmtFer->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $feriados[$d] = true;
        }

        // 2.4 — Buscar faltas classificadas do período
        $stmtMF = $db->prepare("
            SELECT mf.funcionario_id, mf.data, mf.estado
            FROM marcacoes_em_falta mf
            WHERE mf.funcionario_id IN ({$inStr})
              AND mf.data BETWEEN :ini AND :fim
              AND mf.estado != 'pendente'
        ");
        $stmtMF->execute([':ini' => $dataInicio, ':fim' => $dataFim]);
        $faltasRaw = $stmtMF->fetchAll(PDO::FETCH_ASSOC);
        $faltasMap = [];
        foreach ($faltasRaw as $f) {
            $faltasMap[$f['funcionario_id']][$f['data']] = $f['estado'];
        }

        // 2.5 — Buscar marcações do período
        $fimQuery = $dataFim . ' 23:59:59';
        if ($this->periodoTemTurnoNocturnoGlobal($db, $ids, $dataInicio, $dataFim)) {
            $fimQuery = date('Y-m-d', strtotime($dataFim . ' +1 day')) . ' 12:00:00';
        }
        $stmtM = $db->prepare("
            SELECT funcionario_id, tipo, data_hora
            FROM marcacoes
            WHERE funcionario_id IN ({$inStr})
              AND data_hora BETWEEN :ini AND :fim
            ORDER BY funcionario_id, data_hora ASC
        ");
        $stmtM->execute([':ini' => $dataInicio . ' 00:00:00', ':fim' => $fimQuery]);
        $marcacoesRaw = $stmtM->fetchAll(PDO::FETCH_ASSOC);
        $marcacoesMap = [];
        foreach ($marcacoesRaw as $m) {
            $marcacoesMap[$m['funcionario_id']][] = $m;
        }

        // 2.6 — Buscar férias aprovadas do período
        $stmtFP = $db->prepare("
            SELECT fp.funcionario_id, fp.data_inicio, fp.data_fim
            FROM ferias_pedidos fp
            WHERE fp.funcionario_id IN ({$inStr})
              AND fp.estado IN ('aprovado_rh', 'aprovado_supervisor')
              AND fp.data_inicio <= :fim AND fp.data_fim >= :ini
        ");
        $stmtFP->execute([':ini' => $dataInicio, ':fim' => $dataFim]);
        $feriasRaw = $stmtFP->fetchAll(PDO::FETCH_ASSOC);
        $feriasMap = [];
        foreach ($feriasRaw as $f) {
            $feriasMap[$f['funcionario_id']][] = $f;
        }

        // 2.6b — Buscar justificações de ausência aprovadas do período (serviço externo)
        $stmtJA = $db->prepare("
            SELECT funcionario_id, data_inicio, data_fim, tipo
            FROM justificacoes_ausencia
            WHERE funcionario_id IN ({$inStr})
              AND estado = 'aprovado'
              AND data_inicio <= :fim AND data_fim >= :ini
        ");
        $stmtJA->execute([':ini' => $dataInicio, ':fim' => $dataFim]);
        $todasJA = $stmtJA->fetchAll(PDO::FETCH_ASSOC);

        // 2.7 — Processar e gerar linhas
        $escalaService = new \App\Services\EscalaService($db);

        $stmtCfg = $db->query("SELECT valor FROM configuracoes WHERE chave = 'horas_extra_entrada_antecipada'");
        $rowCfg = $stmtCfg->fetch(PDO::FETCH_ASSOC);
        $contarEntradaAntecipada = ($rowCfg && $rowCfg['valor'] === '1');

        $linhas = [];

        foreach ($funcionarios as $func) {
            $fId = (int)$func['id'];
            $codFunc = (string)$func['numero_funcionario'];

            $marcFunc = $marcacoesMap[$fId] ?? [];
            $marcPorDia = [];
            foreach ($marcFunc as $m) {
                $dia = substr($m['data_hora'], 0, 10);
                $hora = (int) substr($m['data_hora'], 11, 2);
                if ($m['tipo'] === 'saida' && $hora < 12) {
                    $diaAnterior = date('Y-m-d', strtotime($dia . ' -1 day'));
                    $turnoAnterior = $escalaService->calcularTurnoEm($fId, $diaAnterior);
                    if ($turnoAnterior && $turnoAnterior['atravessa_dia_civil']) {
                        $dia = $diaAnterior;
                    }
                }
                $marcPorDia[$dia][] = $m;
            }

            $atual = strtotime($dataInicio);
            $fimTs = strtotime($dataFim);

            $acumuladoH01H02 = [];

            while ($atual <= $fimTs) {
                $dataStr = date('Y-m-d', $atual);
                $diaSemana = (int) date('N', $atual);
                $isUtil = ($diaSemana < 6 && !isset($feriados[$dataStr]));

                $hasServicoExterno = false;
                foreach ($todasJA as $ja) {
                    if ($ja['funcionario_id'] == $fId && $ja['tipo'] === 'servico_externo' && $dataStr >= $ja['data_inicio'] && $dataStr <= $ja['data_fim']) {
                        $hasServicoExterno = true;
                        break;
                    }
                }

                // Faltas
                $faltaEmitida = false;
                if (isset($faltasMap[$fId][$dataStr])) {
                    $estado = $faltasMap[$fId][$dataStr];
                    $map = [
                        'injustificada_meio_dia' => ['F08', 0.5],
                        'justificada_trabalho'   => ['F10', 1.0],
                        'justificada_motivo'     => ['F10', 1.0],
                    ];
                    if (isset($map[$estado])) {
                        $linhas[] = $this->formatarLinhaPrimavera('F', $codFunc, $dataStr, $map[$estado][0], (float)$map[$estado][1]);
                        $faltaEmitida = true;
                    }
                }

                // Férias (ferias_pedidos)
                if (isset($feriasMap[$fId])) {
                    foreach ($feriasMap[$fId] as $fp) {
                        if ($dataStr >= $fp['data_inicio'] && $dataStr <= $fp['data_fim'] && $isUtil) {
                            $linhas[] = $this->formatarLinhaPrimavera('F', $codFunc, $dataStr, 'F50', 1.0);
                            break;
                        }
                    }
                }

                // Atrasos e Horas Extra
                $mDia = $marcPorDia[$dataStr] ?? [];
                $turno = $escalaService->calcularTurnoEm($fId, $dataStr);

                $tipoDia = 'util';
                if (isset($feriados[$dataStr])) {
                    $tipoDia = 'feriado';
                } elseif ($diaSemana === 6) {
                    $tipoDia = 'sabado';
                } elseif ($diaSemana === 7) {
                    $tipoDia = 'domingo';
                }

                $regimeEscala = $func['regime_escala'] ?? 'normal';

                $calculoService = new \App\Services\CalculoHorasService();

                $hasFerias = false;
                if (isset($feriasMap[$fId])) {
                    foreach ($feriasMap[$fId] as $fp) {
                        if ($dataStr >= $fp['data_inicio'] && $dataStr <= $fp['data_fim'] && $isUtil) {
                            $hasFerias = true;
                            break;
                        }
                    }
                }

                $hasFaltaJustificada = false;
                if (isset($faltasMap[$fId][$dataStr]) && in_array($faltasMap[$fId][$dataStr], ['justificada_trabalho', 'justificada_motivo'])) {
                    $hasFaltaJustificada = true;
                }

                $resultadoDia = $calculoService->calcularDia($mDia, $turno, $tipoDia, $regimeEscala, $dataStr, $hasServicoExterno, $hasFaltaJustificada, $hasFerias, $contarEntradaAntecipada);

                if (!empty($resultadoDia['is_falta_injustificada']) && !$faltaEmitida) {
                    $linhas[] = $this->formatarLinhaPrimavera('F', $codFunc, $dataStr, 'F03', 1.0);
                }

                if ($resultadoDia['atraso_minutos'] > 0) {
                    $linhas[] = $this->formatarLinhaPrimavera('F', $codFunc, $dataStr, 'F07', $resultadoDia['atraso_minutos'] / 60);
                }

                // Acumulado do mês civil correspondente à data actual
                $mesCivil = substr($dataStr, 0, 7);
                if (!isset($acumuladoH01H02[$mesCivil])) {
                    $acumuladoH01H02[$mesCivil] = 0.0;
                }

                $extraHours = $resultadoDia['minutos_extra'] / 60;
                $extraExtraHours = $resultadoDia['minutos_extra_extraordinario'] / 60;

                if ($extraExtraHours > 0 || $extraHours > 0) {
                    $totalExtra = $extraHours + $extraExtraHours; // Junta tudo, vamos reclassificar com base no tipoDia

                    $isFeriado = ($tipoDia === 'feriado');
                    $isDescanso = false;
                    if ($regimeEscala === 'turnos') {
                        $isDescanso = ($turno && $turno['tipo'] === 'folga');
                    } else {
                        $isDescanso = ($tipoDia === 'sabado' || $tipoDia === 'domingo');
                    }

                    if ($isFeriado) {
                        $linhas[] = $this->formatarLinhaPrimavera('H', $codFunc, $dataStr, 'H04', $totalExtra);
                    } elseif ($isDescanso) {
                        $linhas[] = $this->formatarLinhaPrimavera('H', $codFunc, $dataStr, 'H03', $totalExtra);
                    } else {
                        // Dia normal - usar H01/H02 e acumulador mensal
                        $acumuladoAtual = $acumuladoH01H02[$mesCivil];

                        if ($acumuladoAtual >= 30) {
                            // Já passou das 30h, tudo vai para H02
                            $linhas[] = $this->formatarLinhaPrimavera('H', $codFunc, $dataStr, 'H02', $totalExtra);
                            $acumuladoH01H02[$mesCivil] += $totalExtra;
                        } elseif ($acumuladoAtual + $totalExtra <= 30) {
                            // Ainda dentro do limite das 30h, tudo vai para H01
                            $linhas[] = $this->formatarLinhaPrimavera('H', $codFunc, $dataStr, 'H01', $totalExtra);
                            $acumuladoH01H02[$mesCivil] += $totalExtra;
                        } else {
                            // Dia da transição (passa as 30h)
                            $horasH01 = 30 - $acumuladoAtual;
                            $horasH02 = $totalExtra - $horasH01;

                            $linhas[] = $this->formatarLinhaPrimavera('H', $codFunc, $dataStr, 'H01', $horasH01);
                            $linhas[] = $this->formatarLinhaPrimavera('H', $codFunc, $dataStr, 'H02', $horasH02);

                            $acumuladoH01H02[$mesCivil] += $totalExtra;
                        }
                    }
                }

                $atual = strtotime('+1 day', $atual);
            }
        }

        // 2.8 — Retornar o ficheiro
        $conteudo = implode("\r\n", $linhas);
        $response->getBody()->write($conteudo);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="exportacao_primavera_' . $mes . '.txt"');
    }

    /**
     * GET /api/exportacao/funcionarios
     * Exporta lista de funcionários em CSV para arquivo ou migração
     */
    public function funcionarios(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db   = $this->db();
        $stmt = $db->query("
            SELECT f.numero_funcionario, f.nome_completo, f.nif, f.niss,
                   f.bi_numero, f.data_nascimento, f.genero, f.estado_civil,
                   f.num_dependentes, f.nacionalidade, f.email, f.telefone,
                   f.morada, f.municipio, f.provincia,
                   f.data_admissao, f.tipo_contrato, f.data_fim_contrato,
                   f.vencimento_base_aoa, f.estado,
                   d.nome AS departamento, c.nome AS cargo
            FROM funcionarios f
            LEFT JOIN departamentos d ON f.departamento_id = d.id
            LEFT JOIN cargos c ON f.cargo_id = c.id
            ORDER BY f.numero_funcionario ASC
        ");

        $header = [
            'numero_funcionario','nome_completo','nif','niss','bi_numero',
            'data_nascimento','genero','estado_civil','num_dependentes','nacionalidade',
            'email','telefone','morada','municipio','provincia',
            'data_admissao','tipo_contrato','data_fim_contrato',
            'vencimento_base_aoa','estado','departamento','cargo'
        ];

        $linhas = $stmt->fetchAll(PDO::FETCH_NUM);

        return $this->csvResponse('funcionarios_' . date('Y-m-d') . '.csv', $header, $linhas);
    }

    /**
     * GET /api/exportacao/template-importacao
     * Devolve CSV vazio com o formato correcto para importação
     */
    public function templateImportacao(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $header = [
            'numero_funcionario','nome_completo','nif','niss','bi_numero',
            'data_nascimento','genero','estado_civil','num_dependentes','nacionalidade',
            'email','telefone','morada','municipio','provincia',
            'data_admissao','tipo_contrato','data_fim_contrato',
            'vencimento_base_aoa','departamento','cargo'
        ];

        // Linha de exemplo
        $exemplo = [
            '0001','João Silva','123456789','987654321','001234567LA042',
            '1985-06-15','M','solteiro','0','Angolana',
            'joao.silva@empresa.ao','923000001','Rua da Missão, 42','Luanda','Luanda',
            '2024-01-02','prazo_indeterminado','',
            '150000.00','Recursos Humanos','Técnico de RH'
        ];

        return $this->csvResponse('template_importacao_funcionarios.csv', $header, [$exemplo]);
    }

    private function primaveraHeader(): array
    {
        return [
            'NUMERO_FUNCIONARIO', 'NOME', 'NIF', 'NISS', 'VENCIMENTO_BASE_AOA',
            'DIAS_TRABALHADOS', 'HORAS_EXTRA', 'FALTAS_INJUSTIFICADAS',
            'FALTAS_JUSTIFICADAS', 'NUM_DEPENDENTES', 'TIPO_CONTRATO'
        ];
    }

    private function csvResponse(string $filename, array $header, array $rows): ResponseInterface
    {
        $output = fopen('php://temp', 'r+');

        // BOM UTF-8 para compatibilidade com Excel
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, $header, ';');
        foreach ($rows as $row) {
            fputcsv($output, $row, ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $response = new Response(200);
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->withHeader('Cache-Control', 'no-cache');
    }

    private function formatarLinhaPrimavera(
        string $tipo,
        string $codFunc,
        string $dataStr,
        string $codigo,
        float $quantidade
    ): string {
        $codPadded  = str_pad($codFunc, 10, ' ', STR_PAD_LEFT);
        $data       = date('dmY', strtotime($dataStr));
        $intParte   = (int) $quantidade;
        $decParte   = (int) round(($quantidade - $intParte) * 1000);
        $qtd        = sprintf('%03d.%03d', $intParte, $decParte);
        return $tipo . $codPadded . $data . $codigo . $qtd . '0000';
    }

    private function periodoTemTurnoNocturnoGlobal(PDO $db, array $ids, string $dataInicio, string $dataFim): bool
    {
        $inStr = implode(',', array_map('intval', $ids));
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM funcionario_escala fe
            JOIN escala_turnos et ON fe.escala_id = et.escala_id
            JOIN turnos t ON et.turno_id = t.id
            WHERE fe.funcionario_id IN ({$inStr})
              AND fe.data_inicio <= :fim
              AND (fe.data_fim IS NULL OR fe.data_fim >= :ini)
              AND t.atravessa_dia_civil = 1
        ");
        $stmt->execute([':ini' => $dataInicio, ':fim' => $dataFim]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
