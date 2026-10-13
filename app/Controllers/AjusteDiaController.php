<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class AjusteDiaController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    private function hasAjusteTable(PDO $db): bool
    {
        try {
            $db->query("SELECT 1 FROM ajustes_dia LIMIT 1");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    private function isPeriodoFechado(PDO $db, string $data): bool
    {
        $stmt = $db->prepare("SELECT estado FROM periodos_mensais WHERE estado = 'fechado' AND :data BETWEEN data_inicio AND data_fim LIMIT 1");
        $stmt->execute([':data' => $data]);
        return (bool) $stmt->fetchColumn();
    }

    private function utilizadorId(ServerRequestInterface $request): int
    {
        return (int)($request->getAttribute('auth_user_id') ?? 0);
    }

    private function auditoria(PDO $db, int $userId, string $accao, string $entidade, ?int $entidadeId, ?array $antes, ?array $depois): void
    {
        $stmt = $db->prepare("
            INSERT INTO log_auditoria (utilizador_id, accao, entidade, entidade_id, dados_antes, dados_depois, ip, user_agent)
            VALUES (:uid, :accao, :entidade, :entidade_id, :antes, :depois, :ip, :ua)
        ");
        $stmt->execute([
            ':uid'         => $userId ?: null,
            ':accao'       => $accao,
            ':entidade'    => $entidade,
            ':entidade_id' => $entidadeId,
            ':antes'       => $antes ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
            ':depois'      => $depois ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null,
            ':ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'          => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }

    /**
     * GET /api/ajustes/funcionario/{id}?ano=YYYY
     */
    public function getPorFuncionarioAno(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $db = $this->db();
        if (!$this->hasAjusteTable($db)) {
            return $this->json(503, ['erro' => true, 'codigo' => 'funcionalidade_indisponivel', 'mensagem' => 'Ajustes não estão disponíveis.']);
        }

        $funcId = (int) $args['id'];
        $anoRaw = $request->getQueryParams()['ano'] ?? date('Y');
        $ano = (int)$anoRaw;

        if ($ano < 2000 || $ano > 2100) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'Ano inválido.']);
        }

        $dataInicio = "{$ano}-01-01";
        $dataFim = "{$ano}-12-31";

        // Verifica funcionário
        $stmt = $db->prepare("SELECT id FROM funcionarios WHERE id = :id AND estado = 'activo'");
        $stmt->execute([':id' => $funcId]);
        if (!$stmt->fetchColumn()) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Funcionário não encontrado ou inativo.']);
        }

        // Carrega ajustes
        $ajusteSvc = new \App\Services\AjusteDiaService($db);
        $ajustesFunc = $ajusteSvc->carregarPorFuncionarios([$funcId], $dataInicio, $dataFim)[$funcId] ?? [];

        // Férias
        $stmtF = $db->prepare("SELECT id, data_inicio, data_fim FROM ferias_pedidos WHERE funcionario_id = :fid AND estado = 'aprovado_rh' AND data_inicio <= :fim1 AND data_fim >= :ini1");
        $stmtF->execute([':fid' => $funcId, ':ini1' => $dataInicio, ':fim1' => $dataFim]);
        $todasFerias = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        // Justificações Legadas e Normais
        $stmtJ = $db->prepare("SELECT data_inicio, data_fim, tipo FROM justificacoes WHERE funcionario_id = :fid AND data_inicio <= :fim2 AND data_fim >= :ini2 AND estado = 'aprovada'");
        $stmtJ->execute([':fid' => $funcId, ':ini2' => $dataInicio, ':fim2' => $dataFim]);
        $justificacoesLegadas = $stmtJ->fetchAll(PDO::FETCH_ASSOC);

        $stmtJA = $db->prepare("SELECT id, data_inicio, data_fim, tipo, motivo FROM justificacoes_ausencia WHERE funcionario_id = :fid AND data_inicio <= :fim3 AND data_fim >= :ini3 AND estado = 'aprovado'");
        $stmtJA->execute([':fid' => $funcId, ':ini3' => $dataInicio, ':fim3' => $dataFim]);
        $justificacoesNovas = $stmtJA->fetchAll(PDO::FETCH_ASSOC);

        // Marcações (usado para tem_picagens e calculos se houver justificação sem ajuste)
        $stmtM = $db->prepare("SELECT id, tipo, data_hora, origem FROM marcacoes WHERE funcionario_id = :fid AND data_hora BETWEEN :ini4 AND :fim4");
        $stmtM->execute([':fid' => $funcId, ':ini4' => $dataInicio . ' 00:00:00', ':fim4' => $dataFim . ' 23:59:59']);
        $marcacoesRaw = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        $marcPorDia = [];
        foreach ($marcacoesRaw as $m) {
            $dia = substr($m['data_hora'], 0, 10);
            $marcPorDia[$dia][] = $m;
        }

        // Feriados
        $stmtFer = $db->prepare("
            SELECT data
            FROM feriados
            WHERE data BETWEEN :ini AND :fim
               OR (recorrente = 1 AND DATE_FORMAT(data, '%m-%d') BETWEEN DATE_FORMAT(:ini2, '%m-%d') AND DATE_FORMAT(:fim2, '%m-%d'))
        ");
        $stmtFer->execute([':ini' => $dataInicio, ':fim' => $dataFim, ':ini2' => $dataInicio, ':fim2' => $dataFim]);
        $feriados = array_flip($stmtFer->fetchAll(PDO::FETCH_COLUMN));

        $tiposMap = \App\Services\TipoJustificacaoService::getComportamentoMap($db);
        $estadoDiaService = new \App\Services\EstadoDiaService();
        $escalaService = new \App\Services\EscalaService($db);

        $resultado = [];
        $atual = strtotime($dataInicio);
        $fimTs = strtotime($dataFim);

        while ($atual <= $fimTs) {
            $dia = date('Y-m-d', $atual);

            $feriasDia = [];
            foreach ($todasFerias as $fp) {
                if ($dia >= $fp['data_inicio'] && $dia <= $fp['data_fim']) {
                    $feriasDia[] = $fp;
                }
            }

            $ajusteDia = $ajustesFunc[$dia] ?? null;
            $justificacoesDoDia = [];
            if ($ajusteDia) {
                $justificacoesDoDia[] = [
                    'id' => null,
                    'ajuste_id' => $ajusteDia['id'],
                    'tipo' => $ajusteDia['tipo'],
                    'estado' => 'aprovado',
                    'data_inicio' => $dia,
                    'data_fim' => $dia
                ];
            } else {
                foreach ($justificacoesNovas as $ja) {
                    if ($dia >= $ja['data_inicio'] && $dia <= $ja['data_fim']) {
                        $justificacoesDoDia[] = $ja;
                    }
                }
                foreach ($justificacoesLegadas as $j) {
                    if ($dia >= $j['data_inicio'] && $dia <= $j['data_fim']) {
                        $justificacoesDoDia[] = $j;
                    }
                }
            }

            $mDia = $marcPorDia[$dia] ?? [];
            $turno = $escalaService->calcularTurnoEm($funcId, $dia);

            $estado = $estadoDiaService->determinar($funcId, $dia, $feriasDia, $justificacoesDoDia, $mDia, $turno, $tiposMap);

            $resultado[] = [
                'data' => $dia,
                'ajuste' => $ajusteDia ? [
                    'id' => $ajusteDia['id'],
                    'tipo_id' => $ajusteDia['tipo_id'],
                    'nome' => $ajusteDia['tipo_nome'],
                    'cor' => $ajusteDia['tipo_cor'],
                    'nota' => $ajusteDia['nota'],
                    'lote_id' => $ajusteDia['lote_id']
                ] : null,
                'turno_tipo' => $turno ? $turno['tipo'] : null,
                'ferias' => count($feriasDia) > 0,
                'feriado' => isset($feriados[$dia]),
                'tem_picagens' => count($mDia) > 0,
                'estado_dia' => $estado['estado'],
                'origem_estado' => $estado['origem']
            ];

            $atual = strtotime('+1 day', $atual);
        }

        return $this->json(200, ['ano' => $ano, 'dias' => $resultado]);
    }

    private function parseDatas($datas): array {
        $list = [];
        if (is_array($datas) && isset($datas['inicio']) && isset($datas['fim'])) {
            $iniStr = $datas['inicio'];
            $fimStr = $datas['fim'];

            $dIni = \DateTimeImmutable::createFromFormat('Y-m-d', $iniStr);
            $dFim = \DateTimeImmutable::createFromFormat('Y-m-d', $fimStr);

            $iniParts = explode('-', (string)$iniStr);
            $fimParts = explode('-', (string)$fimStr);

            if (!$dIni || $dIni->format('Y-m-d') !== $iniStr || count($iniParts) !== 3 || !checkdate((int)$iniParts[1], (int)$iniParts[2], (int)$iniParts[0]) ||
                !$dFim || $dFim->format('Y-m-d') !== $fimStr || count($fimParts) !== 3 || !checkdate((int)$fimParts[1], (int)$fimParts[2], (int)$fimParts[0])) {
                throw new \InvalidArgumentException("Datas de início ou fim inválidas (deve ser YYYY-MM-DD real).");
            }
            if ($dIni > $dFim) {
                throw new \InvalidArgumentException("A data de início não pode ser superior à data de fim.");
            }

            $diffDays = $dIni->diff($dFim)->days;
            if ($diffDays > 366) {
                throw new \InvalidArgumentException("O intervalo excede o limite máximo de 366 dias.");
            }

            $curr = $dIni;
            while ($curr <= $dFim) {
                $list[] = $curr->format('Y-m-d');
                $curr = $curr->modify('+1 day');
            }
        } elseif (is_array($datas)) {
             foreach ($datas as $d) {
                 if (!is_string($d)) {
                     throw new \InvalidArgumentException("A lista contém um formato não string.");
                 }
                 $dObj = \DateTimeImmutable::createFromFormat('Y-m-d', $d);
                 $dParts = explode('-', $d);
                 if (!$dObj || $dObj->format('Y-m-d') !== $d || count($dParts) !== 3 || !checkdate((int)$dParts[1], (int)$dParts[2], (int)$dParts[0])) {
                     throw new \InvalidArgumentException("A data fornecida '{$d}' é inválida.");
                 }
                 $list[] = $dObj->format('Y-m-d');
             }
             if (empty($list)) {
                  throw new \InvalidArgumentException("Nenhuma data fornecida.");
             }
             if (count($list) > 366) {
                  throw new \InvalidArgumentException("A lista de datas excede o limite máximo de 366 dias.");
             }
        } else {
             throw new \InvalidArgumentException("Formato de datas inválido.");
        }

        $list = array_unique($list);
        sort($list);
        return $list;
    }

    /**
     * POST /api/ajustes
     */
    public function criarIndividual(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();
        if (!$this->hasAjusteTable($db)) return $this->json(503, ['erro' => true, 'codigo' => 'funcionalidade_indisponivel']);

        $body = $request->getParsedBody() ?? [];
        try {
            $datas = $this->parseDatas($body['datas'] ?? []);
        } catch (\InvalidArgumentException $e) {
            return $this->json(400, ['erro' => true, 'mensagem' => $e->getMessage()]);
        }

        $funcId = isset($body['funcionario_id']) ? (int)$body['funcionario_id'] : null;
        $tipoId = isset($body['tipo_justificacao_id']) ? (int)$body['tipo_justificacao_id'] : null;
        $nota = $body['nota'] ?? null;
        $substituir = !empty($body['substituir']);

        $userId = $this->utilizadorId($request);

        if (!$funcId || empty($datas) || !$tipoId) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'Parâmetros inválidos.']);
        }

        // Valida funcionario activo
        $stmtF = $db->prepare("SELECT id FROM funcionarios WHERE id = :id AND estado = 'activo'");
        $stmtF->execute([':id' => $funcId]);
        if (!$stmtF->fetch()) return $this->json(404, ['erro' => true, 'mensagem' => 'Funcionário inativo ou não existe.']);

        // Valida motivo activo
        $stmtT = $db->prepare("SELECT id, codigo FROM tipos_justificacao WHERE id = :id AND activo = 1");
        $stmtT->execute([':id' => $tipoId]);
        if (!$stmtT->fetch()) return $this->json(400, ['erro' => true, 'mensagem' => 'Motivo inativo ou não existe.']);

        $db->beginTransaction();
        try {
            $plano = $this->calcularPlanoLote($db, [$funcId], $datas, $substituir);
            $resultado = [
                'criados' => count($plano['a_criar']),
                'saltados_ferias' => $plano['saltados_ferias'],
                'saltados_conflito' => $plano['saltados_conflito'],
                'periodo_fechado' => $plano['periodo_fechado']
            ];

            if ($resultado['criados'] > 0) {
                // Substituir os velhos
                if (!empty($plano['update_ids'])) {
                     $inUpd = implode(',', $plano['update_ids']);
                     $db->prepare("UPDATE ajustes_dia SET estado = 'anulado', anulado_por = ?, anulado_em = NOW(), motivo_anulacao = 'Substituído por novo ajuste' WHERE id IN ($inUpd)")->execute([$userId]);
                }

                // Inserir os novos
                $datasParaAjustar = [];
                foreach ($plano['a_criar'] as $pair) {
                    $db->prepare("INSERT INTO ajustes_dia (funcionario_id, data, tipo_justificacao_id, nota, criado_por) VALUES (?, ?, ?, ?, ?)")
                       ->execute([$funcId, $pair['data'], $tipoId, $nota, $userId]);
                    $datasParaAjustar[] = $pair['data'];
                }

                $this->auditoria($db, $userId, 'ajuste_dia.criado', 'ajustes_dia', null, null, ['funcionario_id' => $funcId, 'datas' => $datasParaAjustar, 'tipo_id' => $tipoId]);
            }
            $db->commit();
            return $this->json(200, ['mensagem' => 'Concluído', 'detalhes' => $resultado]);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private function resolveFuncionariosLote(PDO $db, array $selecao, string $dataBase): array
    {
        $fIds = $selecao['funcionarios'] ?? [];
        $deps = $selecao['departamentos'] ?? [];
        $escalas = $selecao['escalas'] ?? [];
        $turmas = $selecao['turmas'] ?? [];

        $idsTotais = [];

        $fIds = array_values(array_unique(array_filter(array_map('intval', $fIds), fn($v) => $v > 0)));
        if (!empty($fIds)) {
            $inFids = implode(',', $fIds);
            $stmt = $db->query("SELECT id FROM funcionarios WHERE estado = 'activo' AND id IN ($inFids)");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fid) $idsTotais[(int)$fid] = true;
        }

        $deps = array_values(array_unique(array_filter(array_map('intval', $deps), fn($v) => $v > 0)));
        if (!empty($deps)) {
            $inDeps = implode(',', $deps);
            $stmt = $db->query("SELECT id FROM funcionarios WHERE estado = 'activo' AND departamento_id IN ($inDeps)");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fid) $idsTotais[(int)$fid] = true;
        }

        $escalas = array_values(array_unique(array_filter(array_map('intval', $escalas), fn($v) => $v > 0)));
        // Turmas are objects like {escala_id, posicao_inicial}, we must filter carefully
        $turmasClean = [];
        foreach ($turmas as $t) {
             if (isset($t['escala_id']) && isset($t['posicao_inicial'])) {
                 $eid = (int)$t['escala_id'];
                 $pid = (int)$t['posicao_inicial'];
                 if ($eid > 0 && $pid >= 0) {
                     $turmasClean[] = ['escala_id' => $eid, 'posicao_inicial' => $pid];
                 }
             }
        }

        if (!empty($escalas) || !empty($turmasClean)) {
            $fEscalas = [];
            // Usamos a atribuição vigente em dataBase (o inicio do lote)
            $stmt = $db->prepare("SELECT funcionario_id, escala_id, posicao_inicial FROM funcionario_escala WHERE data_inicio <= :dt1 AND (data_fim IS NULL OR data_fim >= :dt2)");
            $stmt->execute([':dt1' => $dataBase, ':dt2' => $dataBase]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fe) {
                $fEscalas[$fe['funcionario_id']] = [
                    'escala_id' => (int)$fe['escala_id'],
                    'posicao_inicial' => (int)$fe['posicao_inicial']
                ];
            }

            // Check match
            $stmt = $db->query("SELECT id FROM funcionarios WHERE estado = 'activo'");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fid) {
                if (isset($fEscalas[$fid])) {
                    $es = $fEscalas[$fid];
                    if (in_array($es['escala_id'], $escalas, true)) {
                        $idsTotais[(int)$fid] = true;
                    } else {
                        foreach ($turmasClean as $t) {
                            if ($t['escala_id'] === $es['escala_id'] && $t['posicao_inicial'] === $es['posicao_inicial']) {
                                $idsTotais[(int)$fid] = true;
                            }
                        }
                    }
                }
            }
        }

        return array_keys($idsTotais);
    }

    private function calcularPlanoLote(PDO $db, array $fIds, array $datas, bool $substituir): array
    {
        $plano = [
            'a_criar' => [], // array of ['fid' => x, 'data' => y]
            'update_ids' => [], // array of adjustment ids to annul
            'saltados_ferias' => 0,
            'saltados_conflito' => 0,
            'periodo_fechado' => 0,
            'ja_ajustados' => 0,
            'com_picagens' => 0
        ];

        if (empty($fIds) || empty($datas)) return $plano;

        $inIds = implode(',', array_map('intval', $fIds));
        $dataIni = $datas[0];
        $dataFim = end($datas);

        // Pre-fetch periodos_mensais fechados overlapping batch dates
        $stmtP = $db->prepare("SELECT data_inicio, data_fim FROM periodos_mensais WHERE estado = 'fechado' AND data_fim >= :ini1 AND data_inicio <= :fim1");
        $stmtP->execute([':ini1' => $dataIni, ':fim1' => $dataFim]);
        $periodosFechados = $stmtP->fetchAll(PDO::FETCH_ASSOC);

        // Pre-fetch férias
        $stmtV = $db->prepare("SELECT funcionario_id, data_inicio, data_fim FROM ferias_pedidos WHERE funcionario_id IN ($inIds) AND estado = 'aprovado_rh' AND data_inicio <= :fim2 AND data_fim >= :ini2");
        $stmtV->execute([':ini2' => $dataIni, ':fim2' => $dataFim]);
        $ferias = $stmtV->fetchAll(PDO::FETCH_ASSOC);

        // Pre-fetch ajustes existentes
        $stmtA = $db->prepare("SELECT id, funcionario_id, data FROM ajustes_dia WHERE funcionario_id IN ($inIds) AND data BETWEEN :ini3 AND :fim3 AND activo = 1");
        $stmtA->execute([':ini3' => $dataIni, ':fim3' => $dataFim]);
        $ajustesExistentesMap = [];
        foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $ajustesExistentesMap[$a['funcionario_id']][$a['data']] = (int)$a['id'];
        }

        // Pre-fetch picagens (informativo)
        $stmtM = $db->prepare("SELECT funcionario_id, data_hora FROM marcacoes WHERE funcionario_id IN ($inIds) AND data_hora BETWEEN :ini4 AND :fim4");
        $stmtM->execute([':ini4' => $dataIni . ' 00:00:00', ':fim4' => $dataFim . ' 23:59:59']);
        $picagensMap = [];
        foreach ($stmtM->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $picagensMap[$m['funcionario_id']][substr($m['data_hora'], 0, 10)] = true;
        }

        foreach ($fIds as $fid) {
            foreach ($datas as $dia) {
                // Periodo fechado?
                $isFechado = false;
                foreach ($periodosFechados as $pf) {
                    if ($dia >= $pf['data_inicio'] && $dia <= $pf['data_fim']) {
                        $isFechado = true; break;
                    }
                }
                if ($isFechado) {
                    $plano['periodo_fechado']++;
                    continue;
                }

                // Férias?
                $isFerias = false;
                foreach ($ferias as $fp) {
                    if ($fp['funcionario_id'] == $fid && $dia >= $fp['data_inicio'] && $dia <= $fp['data_fim']) {
                        $isFerias = true; break;
                    }
                }
                if ($isFerias) {
                    $plano['saltados_ferias']++;
                    continue;
                }

                $idExistente = $ajustesExistentesMap[$fid][$dia] ?? null;
                if ($idExistente) {
                    $plano['ja_ajustados']++;
                    if ($substituir) {
                        $plano['update_ids'][] = $idExistente;
                        $plano['a_criar'][] = ['fid' => $fid, 'data' => $dia];
                    } else {
                        $plano['saltados_conflito']++;
                    }
                } else {
                    $plano['a_criar'][] = ['fid' => $fid, 'data' => $dia];
                }

                if (isset($picagensMap[$fid][$dia])) {
                    $plano['com_picagens']++;
                }
            }
        }

        return $plano;
    }

    /**
     * POST /api/ajustes/lote/previsao
     */
    public function previsaoLote(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();
        if (!$this->hasAjusteTable($db)) return $this->json(503, ['erro' => true, 'codigo' => 'funcionalidade_indisponivel']);

        $body = $request->getParsedBody() ?? [];
        $selecao = $body['selecao'] ?? [];
        try {
            $datas = $this->parseDatas($body['datas'] ?? []);
        } catch (\InvalidArgumentException $e) {
            return $this->json(400, ['erro' => true, 'mensagem' => $e->getMessage()]);
        }
        $tipoId = isset($body['tipo_justificacao_id']) ? (int)$body['tipo_justificacao_id'] : null;
        $substituir = !empty($body['substituir']);

        if (empty($datas) || !$tipoId) return $this->json(400, ['erro' => true, 'mensagem' => 'Datas e tipo são obrigatórios.']);

        $dataBase = $datas[0];
        $fIds = $this->resolveFuncionariosLote($db, $selecao, $dataBase);

        if (empty($fIds)) {
            return $this->json(200, ['total_funcionarios' => 0, 'total_dias_a_criar' => 0, 'ja_ajustados' => 0, 'em_ferias' => 0, 'com_picagens' => 0, 'periodo_fechado' => 0, 'sem_escala' => 0]);
        }

        $numDias = count($datas);
        $totalMax = count($fIds) * $numDias;
        if ($totalMax > 20000) return $this->json(422, ['erro' => true, 'mensagem' => 'Limite de 20.000 linhas excedido. Reduza o alcance.']);

        $plano = $this->calcularPlanoLote($db, $fIds, $datas, $substituir);

        // Count sem_escala (informativo)
        $inIds = implode(',', array_map('intval', $fIds));
        $stmtE = $db->prepare("
            SELECT COUNT(f.id)
            FROM funcionarios f
            WHERE f.id IN ($inIds)
              AND NOT EXISTS (SELECT 1 FROM funcionario_escala fe WHERE fe.funcionario_id = f.id AND fe.data_inicio <= :dt1 AND (fe.data_fim IS NULL OR fe.data_fim >= :dt2))
              AND NOT EXISTS (SELECT 1 FROM funcionario_horario fh WHERE fh.funcionario_id = f.id AND fh.data_inicio <= :dt3 AND (fh.data_fim IS NULL OR fh.data_fim >= :dt4))
        ");
        $stmtE->execute([':dt1' => $dataBase, ':dt2' => $dataBase, ':dt3' => $dataBase, ':dt4' => $dataBase]);
        $semEscala = (int)$stmtE->fetchColumn();

        return $this->json(200, [
            'total_funcionarios' => count($fIds),
            'total_dias_a_criar' => count($plano['a_criar']),
            'ja_ajustados' => $plano['ja_ajustados'],
            'em_ferias' => $plano['saltados_ferias'],
            'com_picagens' => $plano['com_picagens'],
            'periodo_fechado' => $plano['periodo_fechado'],
            'sem_escala' => $semEscala
        ]);
    }

    /**
     * POST /api/ajustes/lote
     */
    public function criarLote(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();
        if (!$this->hasAjusteTable($db)) return $this->json(503, ['erro' => true, 'codigo' => 'funcionalidade_indisponivel']);

        $body = $request->getParsedBody() ?? [];
        $selecao = $body['selecao'] ?? [];
        $datasRaw = $body['datas'] ?? [];
        try {
            $datas = $this->parseDatas($datasRaw);
        } catch (\InvalidArgumentException $e) {
            return $this->json(400, ['erro' => true, 'mensagem' => $e->getMessage()]);
        }
        $tipoId = isset($body['tipo_justificacao_id']) ? (int)$body['tipo_justificacao_id'] : null;
        $nota = $body['nota'] ?? null;
        $substituir = !empty($body['substituir']);
        $totalPrevisto = isset($body['total_previsto']) ? (int)$body['total_previsto'] : null;

        $userId = $this->utilizadorId($request);

        if (empty($datas) || !$tipoId || $totalPrevisto === null) return $this->json(400, ['erro' => true, 'mensagem' => 'Parâmetros em falta.']);

        $stmtT = $db->prepare("SELECT id FROM tipos_justificacao WHERE id = :id AND activo = 1");
        $stmtT->execute([':id' => $tipoId]);
        if (!$stmtT->fetch()) return $this->json(400, ['erro' => true, 'mensagem' => 'Motivo inativo ou não existe.']);

        $db->beginTransaction();
        try {
            $fIds = $this->resolveFuncionariosLote($db, $selecao, $datas[0]);
            if (empty($fIds)) {
                $db->rollBack();
                return $this->json(400, ['erro' => true, 'mensagem' => 'Critério não resultou em nenhum funcionário.']);
            }

            $numDias = count($datas);
            $totalMax = count($fIds) * $numDias;
            if ($totalMax > 20000) {
                 $db->rollBack();
                 return $this->json(422, ['erro' => true, 'mensagem' => 'Limite de 20.000 linhas excedido.']);
            }

            $plano = $this->calcularPlanoLote($db, $fIds, $datas, $substituir);
            $aCriarCount = count($plano['a_criar']);

            if ($aCriarCount !== $totalPrevisto) {
                $db->rollBack();
                return $this->json(409, ['erro' => true, 'mensagem' => "Total de {$aCriarCount} ajustes calculado no servidor difere dos {$totalPrevisto} previstos. Tente novamente."]);
            }

            if ($aCriarCount === 0) {
                $db->rollBack();
                return $this->json(200, ['mensagem' => 'Nenhum ajuste necessário.']);
            }

            // Criar registo de lote
            $stmtLote = $db->prepare("INSERT INTO ajustes_lotes (criado_por, tipo_justificacao_id, criterio, datas, nota, total_funcionarios, total_dias) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtLote->execute([
                $userId,
                $tipoId,
                json_encode($selecao, JSON_UNESCAPED_UNICODE),
                json_encode($datasRaw, JSON_UNESCAPED_UNICODE),
                $nota,
                count($fIds),
                $aCriarCount
            ]);
            $loteId = $db->lastInsertId();

            // Substituir (anular antigos)
            if (!empty($plano['update_ids'])) {
                $inUpd = implode(',', $plano['update_ids']);
                $db->prepare("UPDATE ajustes_dia SET estado = 'anulado', anulado_por = ?, anulado_em = NOW(), motivo_anulacao = 'Substituído por lote {$loteId}' WHERE id IN ($inUpd)")->execute([$userId]);
            }

            // Inserção em bloco
            $vals = [];
            $bindParams = [];
            foreach ($plano['a_criar'] as $d) {
                $vals[] = "(?, ?, ?, ?, ?, ?)";
                $bindParams = array_merge($bindParams, [$d['fid'], $d['data'], $tipoId, $nota, $loteId, $userId]);
            }
            // Batches de 1000
            $batchSize = 1000;
            $chunks = array_chunk($vals, $batchSize);
            $paramChunks = array_chunk($bindParams, $batchSize * 6);

            foreach ($chunks as $idx => $chunk) {
                $sqlStr = "INSERT INTO ajustes_dia (funcionario_id, data, tipo_justificacao_id, nota, lote_id, criado_por) VALUES " . implode(', ', $chunk);
                $stmtIns = $db->prepare($sqlStr);
                $stmtIns->execute($paramChunks[$idx]);
            }

            $this->auditoria($db, $userId, 'ajuste_lote.criado', 'ajustes_lotes', (int)$loteId, null, [
                'criterio' => $selecao,
                'total_dias' => $aCriarCount
            ]);

            $db->commit();
            return $this->json(201, ['mensagem' => 'Lote aplicado.', 'total' => $aCriarCount]);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * POST /api/ajustes/{id}/anular
     */
    public function anularIndividual(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $db = $this->db();
        if (!$this->hasAjusteTable($db)) return $this->json(503, ['erro' => true, 'codigo' => 'funcionalidade_indisponivel']);

        $id = (int)$args['id'];
        $body = $request->getParsedBody() ?? [];
        $motivo = $body['motivo_anulacao'] ?? null;
        $userId = $this->utilizadorId($request);

        $stmt = $db->prepare("SELECT * FROM ajustes_dia WHERE id = ?");
        $stmt->execute([$id]);
        $ajuste = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ajuste) return $this->json(404, ['erro' => true, 'mensagem' => 'Ajuste não encontrado.']);
        if ($ajuste['estado'] !== 'activo') return $this->json(400, ['erro' => true, 'mensagem' => 'Já está anulado.']);

        if ($this->isPeriodoFechado($db, $ajuste['data'])) {
             return $this->json(400, ['erro' => true, 'mensagem' => 'Período fechado.']);
        }

        $db->prepare("UPDATE ajustes_dia SET estado = 'anulado', anulado_por = ?, anulado_em = NOW(), motivo_anulacao = ? WHERE id = ?")
           ->execute([$userId, $motivo, $id]);

        $this->auditoria($db, $userId, 'ajuste_dia.anulado', 'ajustes_dia', $id, ['estado' => 'activo'], ['estado' => 'anulado', 'motivo' => $motivo]);

        return $this->json(200, ['mensagem' => 'Anulado.']);
    }

    /**
     * POST /api/ajustes/lote/{id}/anular
     */
    public function anularLote(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $db = $this->db();
        if (!$this->hasAjusteTable($db)) return $this->json(503, ['erro' => true, 'codigo' => 'funcionalidade_indisponivel']);

        $id = (int)$args['id'];
        $body = $request->getParsedBody() ?? [];
        $motivo = $body['motivo_anulacao'] ?? null;
        $userId = $this->utilizadorId($request);

        $stmt = $db->prepare("SELECT * FROM ajustes_lotes WHERE id = ?");
        $stmt->execute([$id]);
        $lote = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lote) return $this->json(404, ['erro' => true, 'mensagem' => 'Lote não encontrado.']);
        if ($lote['estado'] !== 'activo') return $this->json(400, ['erro' => true, 'mensagem' => 'Já está anulado.']);

        $db->beginTransaction();
        try {
            // Verifica periodo fechado em alguma das datas activas
            $stmtD = $db->prepare("SELECT DISTINCT data FROM ajustes_dia WHERE lote_id = ? AND estado = 'activo'");
            $stmtD->execute([$id]);
            $dias = $stmtD->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($dias)) {
                $minDia = min($dias);
                $maxDia = max($dias);
                $stmtP = $db->prepare("SELECT data_inicio, data_fim FROM periodos_mensais WHERE estado = 'fechado' AND data_fim >= :ini1 AND data_inicio <= :fim1");
                $stmtP->execute([':ini1' => $minDia, ':fim1' => $maxDia]);
                $periodosFechados = $stmtP->fetchAll(PDO::FETCH_ASSOC);

                foreach ($dias as $dia) {
                    foreach ($periodosFechados as $pf) {
                        if ($dia >= $pf['data_inicio'] && $dia <= $pf['data_fim']) {
                            $db->rollBack();
                            return $this->json(400, ['erro' => true, 'mensagem' => "Existem dias no lote que pertencem a um período fechado ($dia)."]);
                        }
                    }
                }
            }

            $db->prepare("UPDATE ajustes_lotes SET estado = 'anulado', anulado_por = ?, anulado_em = NOW(), motivo_anulacao = ? WHERE id = ?")
               ->execute([$userId, $motivo, $id]);

            $db->prepare("UPDATE ajustes_dia SET estado = 'anulado', anulado_por = ?, anulado_em = NOW(), motivo_anulacao = ? WHERE lote_id = ? AND estado = 'activo'")
               ->execute([$userId, $motivo, $id]);

            $this->auditoria($db, $userId, 'ajuste_lote.anulado', 'ajustes_lotes', $id, ['estado' => 'activo'], ['estado' => 'anulado', 'criterio' => json_decode($lote['criterio'])]);

            $db->commit();
            return $this->json(200, ['mensagem' => 'Lote anulado.']);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * GET /api/ajustes/lotes
     */
    public function listarLotes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
         $db = $this->db();
         if (!$this->hasAjusteTable($db)) return $this->json(503, ['erro' => true, 'codigo' => 'funcionalidade_indisponivel']);

         $params = $request->getQueryParams();
         $pagina = isset($params['pagina']) ? max(1, (int)$params['pagina']) : 1;
         $limite = isset($params['limite']) ? min(100, max(1, (int)$params['limite'])) : 50;
         $offset = ($pagina - 1) * $limite;

         $stmtCount = $db->query("SELECT COUNT(*) FROM ajustes_lotes");
         $total = (int)$stmtCount->fetchColumn();

         $stmt = $db->prepare("
            SELECT a.*, u.nome AS criado_por_nome, t.nome AS tipo_justificacao_nome
            FROM ajustes_lotes a
            JOIN utilizadores u ON a.criado_por = u.id
            JOIN tipos_justificacao t ON a.tipo_justificacao_id = t.id
            ORDER BY a.criado_em DESC
            LIMIT :offset, :limite
         ");
         $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
         $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
         $stmt->execute();

         $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
         foreach ($lotes as &$lote) {
             $lote['criterio'] = json_decode($lote['criterio']);
             $lote['datas'] = json_decode($lote['datas']);
         }

         return $this->json(200, [
             'dados' => $lotes,
             'paginacao' => [
                 'total' => $total,
                 'pagina' => $pagina,
                 'limite' => $limite,
                 'total_paginas' => ceil($total / $limite)
             ]
         ]);
    }
}
