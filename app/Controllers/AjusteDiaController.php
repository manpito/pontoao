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

    private function auditoria(PDO $db, $user, string $accao, string $entidade, ?int $entidadeId, ?array $antes, ?array $depois): void
    {
        $stmt = $db->prepare("
            INSERT INTO log_auditoria (utilizador_id, accao, entidade, entidade_id, dados_antes, dados_depois, ip, user_agent)
            VALUES (:uid, :accao, :entidade, :entidade_id, :antes, :depois, :ip, :ua)
        ");
        $stmt->execute([
            ':uid'         => $user->id ?? null,
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
        $ano = $request->getQueryParams()['ano'] ?? date('Y');

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

        // Férias e justificações normais
        $stmtF = $db->prepare("SELECT id, data_inicio, data_fim FROM ferias_pedidos WHERE funcionario_id = :fid AND estado = 'aprovado_rh' AND data_inicio <= :fim AND data_fim >= :ini");
        $stmtF->execute([':fid' => $funcId, ':ini' => $dataInicio, ':fim' => $dataFim]);
        $todasFerias = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        // Marcações (usado para tem_picagens e calculos se houver justificação sem ajuste)
        $stmtM = $db->prepare("SELECT id, tipo, data_hora, origem FROM marcacoes WHERE funcionario_id = :fid AND data_hora BETWEEN :ini AND :fim");
        $stmtM->execute([':fid' => $funcId, ':ini' => $dataInicio . ' 00:00:00', ':fim' => $dataFim . ' 23:59:59']);
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
                    'estado' => 'aprovado'
                ];
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

    private function parseDatas($datas) {
        // Accepts list of YYYY-MM-DD or object {inicio, fim}
        if (is_array($datas) && isset($datas['inicio']) && isset($datas['fim'])) {
            $ini = strtotime($datas['inicio']);
            $fim = strtotime($datas['fim']);
            $list = [];
            while ($ini <= $fim) {
                $list[] = date('Y-m-d', $ini);
                $ini = strtotime('+1 day', $ini);
            }
            return $list;
        }
        return is_array($datas) ? $datas : [];
    }

    /**
     * POST /api/ajustes
     */
    public function criarIndividual(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();
        if (!$this->hasAjusteTable($db)) return $this->json(503, ['erro' => true, 'codigo' => 'funcionalidade_indisponivel']);

        $body = $request->getParsedBody() ?? [];
        $funcId = $body['funcionario_id'] ?? null;
        $datas = $this->parseDatas($body['datas'] ?? []);
        $tipoId = $body['tipo_justificacao_id'] ?? null;
        $nota = $body['nota'] ?? null;
        $substituir = !empty($body['substituir']);

        $user = $request->getAttribute('auth_user');

        if (!$funcId || empty($datas) || !$tipoId) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'Parâmetros inválidos.']);
        }
        if (count($datas) > 366) return $this->json(400, ['erro' => true, 'mensagem' => 'Máximo de 366 dias excedido.']);

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
            $resultado = ['criados' => 0, 'saltados_ferias' => 0, 'saltados_conflito' => 0, 'periodo_fechado' => 0];

            foreach ($datas as $dia) {
                if ($this->isPeriodoFechado($db, $dia)) {
                    $resultado['periodo_fechado']++;
                    continue;
                }

                // Ferias?
                $stmtFerias = $db->prepare("SELECT id FROM ferias_pedidos WHERE funcionario_id = ? AND estado = 'aprovado_rh' AND data_inicio <= ? AND data_fim >= ?");
                $stmtFerias->execute([$funcId, $dia, $dia]);
                if ($stmtFerias->fetchColumn()) {
                    $resultado['saltados_ferias']++;
                    continue;
                }

                // Conflito
                $stmtCheck = $db->prepare("SELECT id FROM ajustes_dia WHERE funcionario_id = ? AND data = ? AND activo = 1");
                $stmtCheck->execute([$funcId, $dia]);
                $existente = $stmtCheck->fetchColumn();

                if ($existente) {
                    if (!$substituir) {
                        $resultado['saltados_conflito']++;
                        continue;
                    } else {
                        $db->prepare("UPDATE ajustes_dia SET estado = 'anulado', anulado_por = ?, anulado_em = NOW(), motivo_anulacao = 'Substituído por novo ajuste' WHERE id = ?")
                           ->execute([$user->id, $existente]);
                        $this->auditoria($db, $user, 'ajuste_dia.anulado', 'ajustes_dia', (int)$existente, null, null);
                    }
                }

                $db->prepare("INSERT INTO ajustes_dia (funcionario_id, data, tipo_justificacao_id, nota, criado_por) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$funcId, $dia, $tipoId, $nota, $user->id]);
                $idCriado = $db->lastInsertId();
                $this->auditoria($db, $user, 'ajuste_dia.criado', 'ajustes_dia', (int)$idCriado, null, ['data' => $dia, 'tipo_id' => $tipoId]);
                $resultado['criados']++;
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
        foreach ($fIds as $fid) $idsTotais[(int)$fid] = true;

        if (!empty($deps)) {
            $inDeps = implode(',', array_map('intval', $deps));
            $stmt = $db->query("SELECT id FROM funcionarios WHERE estado = 'activo' AND departamento_id IN ($inDeps)");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fid) $idsTotais[(int)$fid] = true;
        }

        if (!empty($escalas) || !empty($turmas)) {
            $fEscalas = [];
            // Usamos a atribuição vigente em dataBase (o inicio do lote)
            $stmt = $db->prepare("SELECT funcionario_id, escala_id, posicao_inicial FROM funcionario_escala WHERE data_inicio <= :dt AND (data_fim IS NULL OR data_fim >= :dt)");
            $stmt->execute([':dt' => $dataBase]);
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
                    if (in_array($es['escala_id'], array_map('intval', $escalas))) {
                        $idsTotais[(int)$fid] = true;
                    } else {
                        foreach ($turmas as $t) {
                            if ((int)$t['escala_id'] === $es['escala_id'] && (int)$t['posicao_inicial'] === $es['posicao_inicial']) {
                                $idsTotais[(int)$fid] = true;
                            }
                        }
                    }
                }
            }
        }

        return array_keys($idsTotais);
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
        $datas = $this->parseDatas($body['datas'] ?? []);
        $tipoId = $body['tipo_justificacao_id'] ?? null;

        if (empty($datas) || !$tipoId) return $this->json(400, ['erro' => true, 'mensagem' => 'Datas e tipo são obrigatórios.']);
        if (count($datas) > 366) return $this->json(400, ['erro' => true, 'mensagem' => 'Máximo de 366 dias excedido.']);

        $dataBase = $datas[0];
        $fIds = $this->resolveFuncionariosLote($db, $selecao, $dataBase);

        if (empty($fIds)) {
            return $this->json(200, ['total_funcionarios' => 0, 'total_dias_a_criar' => 0, 'ja_ajustados' => 0, 'em_ferias' => 0, 'com_picagens' => 0, 'periodo_fechado' => 0, 'sem_escala' => 0]);
        }

        $numDias = count($datas);
        $totalMax = count($fIds) * $numDias;
        if ($totalMax > 20000) return $this->json(422, ['erro' => true, 'mensagem' => 'Limite de 20.000 linhas excedido. Reduza o alcance.']);

        $inIds = implode(',', array_map('intval', $fIds));

        // Férias
        $stmtV = $db->prepare("SELECT funcionario_id, data_inicio, data_fim FROM ferias_pedidos WHERE funcionario_id IN ($inIds) AND estado = 'aprovado_rh' AND data_inicio <= :fim AND data_fim >= :ini");
        $stmtV->execute([':ini' => $datas[0], ':fim' => end($datas)]);
        $ferias = $stmtV->fetchAll(PDO::FETCH_ASSOC);

        // Ajustes existentes
        $stmtA = $db->prepare("SELECT funcionario_id, data FROM ajustes_dia WHERE funcionario_id IN ($inIds) AND data BETWEEN :ini AND :fim AND activo = 1");
        $stmtA->execute([':ini' => $datas[0], ':fim' => end($datas)]);
        $ajustesExistentesMap = [];
        foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $a) $ajustesExistentesMap[$a['funcionario_id']][$a['data']] = true;

        // Picagens (informativo)
        $stmtM = $db->prepare("SELECT funcionario_id, data_hora FROM marcacoes WHERE funcionario_id IN ($inIds) AND data_hora BETWEEN :ini AND :fim");
        $stmtM->execute([':ini' => $datas[0] . ' 00:00:00', ':fim' => end($datas) . ' 23:59:59']);
        $picagensMap = [];
        foreach ($stmtM->fetchAll(PDO::FETCH_ASSOC) as $m) $picagensMap[$m['funcionario_id']][substr($m['data_hora'], 0, 10)] = true;

        $jaAjustados = 0;
        $emFerias = 0;
        $comPicagens = 0;
        $periodoFechado = 0;
        $aCriar = 0;

        foreach ($fIds as $fid) {
            foreach ($datas as $dia) {
                if ($this->isPeriodoFechado($db, $dia)) {
                    $periodoFechado++;
                    continue;
                }
                $isFerias = false;
                foreach ($ferias as $fp) {
                    if ($fp['funcionario_id'] == $fid && $dia >= $fp['data_inicio'] && $dia <= $fp['data_fim']) {
                        $isFerias = true; break;
                    }
                }
                if ($isFerias) { $emFerias++; continue; }

                if (isset($ajustesExistentesMap[$fid][$dia])) {
                    $jaAjustados++;
                } else {
                    $aCriar++;
                }

                if (isset($picagensMap[$fid][$dia])) {
                    $comPicagens++;
                }
            }
        }

        return $this->json(200, [
            'total_funcionarios' => count($fIds),
            'total_dias_a_criar' => $aCriar,
            'ja_ajustados' => $jaAjustados,
            'em_ferias' => $emFerias,
            'com_picagens' => $comPicagens,
            'periodo_fechado' => $periodoFechado,
            'sem_escala' => 0
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
        $datas = $this->parseDatas($datasRaw);
        $tipoId = $body['tipo_justificacao_id'] ?? null;
        $nota = $body['nota'] ?? null;
        $substituir = !empty($body['substituir']);
        $totalPrevisto = isset($body['total_previsto']) ? (int)$body['total_previsto'] : null;

        $user = $request->getAttribute('auth_user');

        if (empty($datas) || !$tipoId || $totalPrevisto === null) return $this->json(400, ['erro' => true, 'mensagem' => 'Parâmetros em falta.']);
        if (count($datas) > 366) return $this->json(400, ['erro' => true, 'mensagem' => 'Máximo de 366 dias excedido.']);

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

            $inIds = implode(',', array_map('intval', $fIds));
            $stmtV = $db->prepare("SELECT funcionario_id, data_inicio, data_fim FROM ferias_pedidos WHERE funcionario_id IN ($inIds) AND estado = 'aprovado_rh' AND data_inicio <= :fim AND data_fim >= :ini");
            $stmtV->execute([':ini' => $datas[0], ':fim' => end($datas)]);
            $ferias = $stmtV->fetchAll(PDO::FETCH_ASSOC);

            $stmtA = $db->prepare("SELECT id, funcionario_id, data FROM ajustes_dia WHERE funcionario_id IN ($inIds) AND data BETWEEN :ini AND :fim AND activo = 1");
            $stmtA->execute([':ini' => $datas[0], ':fim' => end($datas)]);
            $ajustesExistentesMap = [];
            foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $a) $ajustesExistentesMap[$a['funcionario_id']][$a['data']] = $a['id'];

            $insertData = [];
            $updateIds = [];
            $aCriarCount = 0;

            foreach ($fIds as $fid) {
                foreach ($datas as $dia) {
                    if ($this->isPeriodoFechado($db, $dia)) continue;
                    $isFerias = false;
                    foreach ($ferias as $fp) {
                        if ($fp['funcionario_id'] == $fid && $dia >= $fp['data_inicio'] && $dia <= $fp['data_fim']) {
                            $isFerias = true; break;
                        }
                    }
                    if ($isFerias) continue;

                    $idExistente = $ajustesExistentesMap[$fid][$dia] ?? null;
                    if ($idExistente) {
                        if ($substituir) {
                            $updateIds[] = (int)$idExistente;
                            $insertData[] = [$fid, $dia];
                            $aCriarCount++;
                        }
                    } else {
                        $insertData[] = [$fid, $dia];
                        $aCriarCount++;
                    }
                }
            }

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
                $user->id,
                $tipoId,
                json_encode($selecao, JSON_UNESCAPED_UNICODE),
                json_encode($datasRaw, JSON_UNESCAPED_UNICODE),
                $nota,
                count($fIds),
                $aCriarCount
            ]);
            $loteId = $db->lastInsertId();

            // Substituir (anular antigos)
            if (!empty($updateIds)) {
                $inUpd = implode(',', $updateIds);
                $db->prepare("UPDATE ajustes_dia SET estado = 'anulado', anulado_por = ?, anulado_em = NOW(), motivo_anulacao = 'Substituído por lote {$loteId}' WHERE id IN ($inUpd)")->execute([$user->id]);
            }

            // Inserção em bloco
            $vals = [];
            $bindParams = [];
            foreach ($insertData as $d) {
                $vals[] = "(?, ?, ?, ?, ?, ?)";
                $bindParams = array_merge($bindParams, [$d[0], $d[1], $tipoId, $nota, $loteId, $user->id]);
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

            $this->auditoria($db, $user, 'ajuste_lote.criado', 'ajustes_lotes', (int)$loteId, null, [
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
        $user = $request->getAttribute('auth_user');

        $stmt = $db->prepare("SELECT * FROM ajustes_dia WHERE id = ?");
        $stmt->execute([$id]);
        $ajuste = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ajuste) return $this->json(404, ['erro' => true, 'mensagem' => 'Ajuste não encontrado.']);
        if ($ajuste['estado'] !== 'activo') return $this->json(400, ['erro' => true, 'mensagem' => 'Já está anulado.']);

        if ($this->isPeriodoFechado($db, $ajuste['data'])) {
             return $this->json(400, ['erro' => true, 'mensagem' => 'Período fechado.']);
        }

        $db->prepare("UPDATE ajustes_dia SET estado = 'anulado', anulado_por = ?, anulado_em = NOW(), motivo_anulacao = ? WHERE id = ?")
           ->execute([$user->id, $motivo, $id]);

        $this->auditoria($db, $user, 'ajuste_dia.anulado', 'ajustes_dia', $id, ['estado' => 'activo'], ['estado' => 'anulado', 'motivo' => $motivo]);

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
        $user = $request->getAttribute('auth_user');

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
            foreach ($stmtD->fetchAll(PDO::FETCH_COLUMN) as $dia) {
                 if ($this->isPeriodoFechado($db, $dia)) {
                      $db->rollBack();
                      return $this->json(400, ['erro' => true, 'mensagem' => "Existem dias no lote que pertencem a um período fechado ($dia)."]);
                 }
            }

            $db->prepare("UPDATE ajustes_lotes SET estado = 'anulado', anulado_por = ?, anulado_em = NOW(), motivo_anulacao = ? WHERE id = ?")
               ->execute([$user->id, $motivo, $id]);

            $db->prepare("UPDATE ajustes_dia SET estado = 'anulado', anulado_por = ?, anulado_em = NOW(), motivo_anulacao = ? WHERE lote_id = ? AND estado = 'activo'")
               ->execute([$user->id, $motivo, $id]);

            $this->auditoria($db, $user, 'ajuste_lote.anulado', 'ajustes_lotes', $id, ['estado' => 'activo'], ['estado' => 'anulado', 'criterio' => json_decode($lote['criterio'])]);

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

         $stmt = $db->query("
            SELECT a.*, u.nome AS criado_por_nome, t.nome AS tipo_justificacao_nome
            FROM ajustes_lotes a
            JOIN utilizadores u ON a.criado_por = u.id
            JOIN tipos_justificacao t ON a.tipo_justificacao_id = t.id
            ORDER BY a.criado_em DESC
            LIMIT 50
         ");

         $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
         foreach ($lotes as &$lote) {
             $lote['criterio'] = json_decode($lote['criterio']);
             $lote['datas'] = json_decode($lote['datas']);
         }

         return $this->json(200, ['dados' => $lotes]);
    }
}
