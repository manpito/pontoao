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
        $params = $request->getQueryParams();
        $mes    = $params['mes'] ?? date('Y-m');
        $depId  = !empty($params['departamento_id']) ? (int) $params['departamento_id'] : null;

        [$ano, $mesNum] = explode('-', $mes);
        $dataInicio = "{$mes}-01";
        $dataFim    = $mes . '-' . date('t', strtotime($dataInicio));

        $db = $this->db();

        // Buscar funcionários activos
        $where = ["f.estado = 'activo'"];
        $bind  = [];
        if ($depId) { $where[] = 'f.departamento_id = :did'; $bind[':did'] = $depId; }

        $stmtF = $db->prepare("
            SELECT f.id, f.numero_funcionario, f.nome_completo, f.nif, f.niss,
                   f.vencimento_base_aoa, f.num_dependentes, f.tipo_contrato,
                   h.horas_dia AS horas_esperadas_dia
            FROM funcionarios f
            LEFT JOIN funcionario_horario fh ON fh.funcionario_id = f.id AND fh.data_fim IS NULL
            LEFT JOIN horarios h ON fh.horario_id = h.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY f.numero_funcionario ASC
        ");
        $stmtF->execute($bind);
        $funcionarios = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        // Buscar feriados do mês
        $feriados = [];
        $stmtFer = $db->query("SELECT data FROM feriados WHERE data BETWEEN '{$dataInicio}' AND '{$dataFim}'");
        foreach ($stmtFer->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $feriados[$d] = true;
        }

        // Buscar marcações do mês (todos os funcionários)
        $ids   = array_column($funcionarios, 'id');
        if (empty($ids)) {
            return $this->csvResponse("exportacao_primavera_{$mes}.csv", $this->primaveraHeader(), []);
        }
        $inStr = implode(',', $ids);

        $stmtM = $db->query("
            SELECT funcionario_id, tipo, data_hora
            FROM marcacoes
            WHERE funcionario_id IN ({$inStr})
              AND data_hora BETWEEN '{$dataInicio} 00:00:00' AND '{$dataFim} 23:59:59'
            ORDER BY funcionario_id, data_hora ASC
        ");
        $todasMarcacoes = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        // Buscar justificações aprovadas do mês
        $stmtJ = $db->query("
            SELECT funcionario_id, data_inicio, data_fim
            FROM justificacoes
            WHERE funcionario_id IN ({$inStr})
              AND estado = 'aprovada'
              AND data_inicio <= '{$dataFim}' AND data_fim >= '{$dataInicio}'
        ");
        $justificacoes = $stmtJ->fetchAll(PDO::FETCH_ASSOC);

        $linhas = [];

        foreach ($funcionarios as $func) {
            $marcFunc = array_filter($todasMarcacoes, fn($m) => $m['funcionario_id'] == $func['id']);
            $justFunc = array_filter($justificacoes, fn($j) => $j['funcionario_id'] == $func['id']);

            $marcPorDia = [];
            foreach ($marcFunc as $m) {
                $dia = substr($m['data_hora'], 0, 10);
                $marcPorDia[$dia][] = $m;
            }

            $diasTrabalhados     = 0;
            $minutosExtra        = 0;
            $faltasInjustificadas = 0;
            $faltasJustificadas  = 0;
            $horasEsperadasDia   = (float) ($func['horas_esperadas_dia'] ?? 8);

            $atual = strtotime($dataInicio);
            $fim   = strtotime($dataFim);

            while ($atual <= $fim) {
                $dataStr   = date('Y-m-d', $atual);
                $diaSemana = (int) date('N', $atual);
                $atual     = strtotime('+1 day', $atual);

                if ($diaSemana >= 6 || isset($feriados[$dataStr])) continue;

                if (!empty($marcPorDia[$dataStr])) {
                    $diasTrabalhados++;

                    // Calcular horas extra do dia
                    $entrada = null; $saida = null; $intervalo = 0; $iniInt = null;
                    foreach ($marcPorDia[$dataStr] as $m) {
                        $ts = strtotime($m['data_hora']);
                        match ($m['tipo']) {
                            'entrada'          => $entrada = $ts,
                            'saida'            => $saida = $ts,
                            'inicio_intervalo' => $iniInt = $ts,
                            'fim_intervalo'    => $intervalo += $iniInt ? (int)(($ts - $iniInt) / 60) : 0,
                            default            => null
                        };
                    }
                    if ($entrada && $saida) {
                        $minutos = (int)(($saida - $entrada) / 60) - $intervalo;
                        $minutosExtra += max(0, $minutos - (int)($horasEsperadasDia * 60));
                    }
                } else {
                    // Verificar justificação
                    $justificado = false;
                    foreach ($justFunc as $j) {
                        if ($dataStr >= $j['data_inicio'] && $dataStr <= $j['data_fim']) {
                            $justificado = true; break;
                        }
                    }
                    if ($justificado) $faltasJustificadas++; else $faltasInjustificadas++;
                }
            }

            $linhas[] = [
                $func['numero_funcionario'],
                $func['nome_completo'],
                $func['nif'] ?? '',
                $func['niss'] ?? '',
                number_format((float)$func['vencimento_base_aoa'], 2, '.', ''),
                $diasTrabalhados,
                number_format($minutosExtra / 60, 2, '.', ''),
                $faltasInjustificadas,
                $faltasJustificadas,
                $func['num_dependentes'],
                $func['tipo_contrato'],
            ];
        }

        return $this->csvResponse("exportacao_primavera_{$mes}.csv", $this->primaveraHeader(), $linhas);
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
}
