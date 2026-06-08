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
        $dataInicio = $mes . '-01';
        $dataFim    = $mes . '-' . date('t', strtotime($dataInicio));
        $db         = $this->db();

        // 2.2 — Buscar funcionários activos
        $stmtF = $db->query("
            SELECT f.id, f.numero_funcionario
            FROM funcionarios f
            WHERE f.estado = 'activo'
            ORDER BY f.numero_funcionario ASC
        ");
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

        // 2.7 — Processar e gerar linhas
        $escalaService = new \App\Services\EscalaService($db);
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

            while ($atual <= $fimTs) {
                $dataStr = date('Y-m-d', $atual);
                $diaSemana = (int) date('N', $atual);
                $isUtil = ($diaSemana < 6 && !isset($feriados[$dataStr]));

                // Faltas (marcacoes_em_falta)
                if (isset($faltasMap[$fId][$dataStr])) {
                    $estado = $faltasMap[$fId][$dataStr];
                    $map = [
                        'injustificada_falta'    => ['F03', 1.0],
                        'injustificada_meio_dia' => ['F08', 0.5],
                        'justificada_trabalho'   => ['F10', 1.0],
                        'justificada_motivo'     => ['F10', 1.0],
                    ];
                    if (isset($map[$estado])) {
                        $linhas[] = $this->formatarLinhaPrimavera('F', $codFunc, $dataStr, $map[$estado][0], (float)$map[$estado][1]);
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

                if ($turno) {
                    $entrada = null; $saida = null; $minutosIntervalo = 0; $iniInt = null;
                    foreach ($mDia as $m) {
                        $ts = strtotime($m['data_hora']);
                        if ($m['tipo'] === 'entrada' && !$entrada) $entrada = $ts;
                        elseif ($m['tipo'] === 'saida') $saida = $ts;
                        elseif ($m['tipo'] === 'inicio_intervalo') $iniInt = $ts;
                        elseif ($m['tipo'] === 'fim_intervalo' && $iniInt) {
                            $minutosIntervalo += (int) round(($ts - $iniInt) / 60);
                            $iniInt = null;
                        }
                    }

                    if ($entrada) {
                        // Atrasos
                        if ($turno['tipo'] !== 'folga' && $turno['hora_entrada']) {
                            $tolerancia = $turno['tolerancia_entrada_min'] ?? 10;
                            $entradaPrevista = strtotime($dataStr . ' ' . $turno['hora_entrada']);
                            $atrasoMinutos = (int) round(($entrada - $entradaPrevista) / 60);
                            if ($atrasoMinutos > $tolerancia) {
                                $linhas[] = $this->formatarLinhaPrimavera('F', $codFunc, $dataStr, 'F07', ($atrasoMinutos - $tolerancia) / 60);
                            }
                        }

                        // Horas Extra
                        $horasEfectivas = ($turno['tipo'] === 'folga') ? 0 : ($turno['horas_efectivas'] ?? 8);
                        $saidaCalc = $saida ?? ($entrada + ($horasEfectivas * 3600));
                        $minutosTrabalhados = (int) round(($saidaCalc - $entrada) / 60) - $minutosIntervalo;
                        $minutosEsperados   = (int) ($horasEfectivas * 60);
                        $minutosExtra = max(0, $minutosTrabalhados - $minutosEsperados);

                        if ($minutosExtra > 0) {
                            $codigo = ($diaSemana >= 6 || isset($feriados[$dataStr])) ? 'H04' : 'H02';
                            $linhas[] = $this->formatarLinhaPrimavera('H', $codFunc, $dataStr, $codigo, $minutosExtra / 60);
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
        $codPadded  = str_pad($codFunc, 10, '0', STR_PAD_LEFT);
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
