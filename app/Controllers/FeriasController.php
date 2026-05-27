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
 * FeriasController — Gestão de férias
 * Lei n.º 12/23, de 27 de Dezembro (revoga LGT 7/15)
 * - Mínimo 22 dias úteis por ano (Art.º 207.º)
 * - Proporcionalidade no primeiro ano
 * - Faltas injustificadas podem reduzir férias (sem perda automática total)
 * - Limite mínimo garantido: 12 dias úteis mesmo com faltas
 */
class FeriasController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/ferias
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $user   = $request->getAttribute('auth_user');
        $db     = $this->db();

        $where = [];
        $bind  = [];

        // Filtro supervisor: apenas a sua equipa
        if ($user && $user->perfil === 'supervisor' && !empty($user->funcionario_id)) {
            $where[] = 'f.supervisor_id = :sid';
            $bind[':sid'] = (int) $user->funcionario_id;
        } else
        if ($user && $user->perfil === 'funcionario') {
            $where[]        = 'fp.funcionario_id = :fid';
            $bind[':fid']   = (int) $user->sub;
        } elseif (!empty($params['funcionario_id'])) {
            $where[]        = 'fp.funcionario_id = :fid';
            $bind[':fid']   = (int) $params['funcionario_id'];
        }

        if (!empty($params['estado'])) {
            $where[]          = 'fp.estado = :estado';
            $bind[':estado']  = $params['estado'];
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("
            SELECT
                fp.id, fp.data_inicio, fp.data_fim, fp.dias_uteis,
                fp.estado, fp.motivo_rejeicao, fp.criado_em,
                f.nome_completo AS funcionario, f.numero_funcionario,
                fe.dias_direito, fe.dias_gozados, fe.dias_pendentes,
                (fe.dias_pendentes - COALESCE(
                    (SELECT SUM(fp2.dias_uteis)
                     FROM ferias_pedidos fp2
                     WHERE fp2.funcionario_id = fp.funcionario_id
                       AND fp2.ano = fe.ano
                       AND fp2.estado IN ('pendente','aprovado_supervisor')
                       AND fp2.id != fp.id
                       AND YEAR(fp2.data_inicio) = fe.ano), 0
                )) AS dias_realmente_disponiveis
            FROM ferias_pedidos fp
            JOIN funcionarios f ON fp.funcionario_id = f.id
            LEFT JOIN ferias fe ON fe.funcionario_id = fp.funcionario_id
                AND fe.ano = YEAR(fp.data_inicio)
            {$whereStr}
            ORDER BY fp.criado_em DESC
        ");
        $stmt->execute($bind);

        return $this->json(200, ['dados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * GET /api/ferias/saldo/{funcionario_id}
     * Devolve saldo real disponível considerando pedidos pendentes
     */
    public function saldo(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $fid = (int) $args['funcionario_id'];
        $ano = (int) ($request->getQueryParams()['ano'] ?? date('Y'));
        $db  = $this->db();

        $saldo = $this->calcularSaldoReal($db, $fid, $ano);

        return $this->json(200, ['dados' => $saldo]);
    }

    /**
     * POST /api/ferias
     * Submeter pedido de férias com validação Lei 12/23
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $user = $request->getAttribute('auth_user');
        $db   = $this->db();

        $funcionarioId = (int) ($body['funcionario_id'] ?? ($user ? $user->sub : 0));
        $dataInicio    = $body['data_inicio'] ?? '';
        $dataFim       = $body['data_fim']    ?? '';

        if (!$funcionarioId || !$dataInicio || !$dataFim) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'funcionario_id, data_inicio e data_fim são obrigatórios.']);
        }

        if ($dataFim < $dataInicio) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'A data de fim não pode ser anterior à data de início.']);
        }

        $ano       = (int) substr($dataInicio, 0, 4);
        $diasUteis = $this->calcularDiasUteis($db, $dataInicio, $dataFim);

        if ($diasUteis <= 0) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'O período seleccionado não contém dias úteis.']);
        }

        // Calcular saldo real disponível (deduzindo pedidos pendentes)
        $saldo = $this->calcularSaldoReal($db, $funcionarioId, $ano);

        if ($saldo['disponivel_real'] <= 0) {
            return $this->json(409, [
                'erro'     => true,
                'mensagem' => "Sem saldo disponível. Já tem {$saldo['dias_em_pedidos_activos']} dia(s) em pedidos pendentes ou aguarda aprovação.",
            ]);
        }

        if ($diasUteis > $saldo['disponivel_real']) {
            return $this->json(409, [
                'erro'     => true,
                'mensagem' => "Saldo insuficiente. Disponível: {$saldo['disponivel_real']} dias úteis. Solicitado: {$diasUteis} dias. Tem {$saldo['dias_em_pedidos_activos']} dia(s) em pedidos activos.",
            ]);
        }

        // Verificar sobreposição com pedidos existentes aprovados ou pendentes
        $overlap = $db->prepare("
            SELECT COUNT(*) FROM ferias_pedidos
            WHERE funcionario_id = :fid
              AND estado NOT IN ('rejeitado','cancelado')
              AND data_inicio <= :fim AND data_fim >= :ini
        ");
        $overlap->execute([':fid' => $funcionarioId, ':ini' => $dataInicio, ':fim' => $dataFim]);
        if ((int) $overlap->fetchColumn() > 0) {
            return $this->json(409, [
                'erro'     => true,
                'mensagem' => 'O período solicitado sobrepõe-se a um pedido de férias existente.',
            ]);
        }

        $stmt = $db->prepare("
            INSERT INTO ferias_pedidos (funcionario_id, data_inicio, data_fim, dias_uteis, estado)
            VALUES (:fid, :ini, :fim, :dias, 'pendente')
        ");
        $stmt->execute([
            ':fid'  => $funcionarioId,
            ':ini'  => $dataInicio,
            ':fim'  => $dataFim,
            ':dias' => $diasUteis,
        ]);

        return $this->json(201, [
            'mensagem'   => 'Pedido de férias submetido com sucesso.',
            'id'         => (int) $db->lastInsertId(),
            'dias_uteis' => $diasUteis,
            'saldo_apos_pedido' => $saldo['disponivel_real'] - $diasUteis,
        ]);
    }

    /**
     * PUT /api/ferias/{id}/aprovar
     */
    public function aprovar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $user = $request->getAttribute('auth_user');
        $db   = $this->db();

        $stmt = $db->prepare("SELECT * FROM ferias_pedidos WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pedido) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Pedido não encontrado.']);
        }

        $accao  = $body['accao'] ?? 'aprovar';
        $perfil = $user ? $user->perfil : '';

        if ($accao === 'rejeitar') {
            $db->prepare("UPDATE ferias_pedidos SET estado = 'rejeitado', motivo_rejeicao = :motivo WHERE id = :id")
               ->execute([':motivo' => $body['motivo'] ?? 'Rejeitado.', ':id' => $id]);
            return $this->json(200, ['mensagem' => 'Pedido rejeitado.']);
        }

        if ($perfil === 'supervisor' && $pedido['estado'] === 'pendente') {
            $db->prepare("UPDATE ferias_pedidos SET estado = 'aprovado_supervisor', aprovado_supervisor_por = :uid WHERE id = :id")
               ->execute([':uid' => (int) $user->sub, ':id' => $id]);
            return $this->json(200, ['mensagem' => 'Pedido aprovado pelo supervisor. Aguarda aprovação de RH.']);
        }

        if (in_array($perfil, ['rh_manager', 'super_admin_tenant']) && in_array($pedido['estado'], ['pendente', 'aprovado_supervisor'])) {

            // Verificar saldo real no momento da aprovação
            $ano   = (int) ($pedido['ano'] ?? substr($pedido['data_inicio'], 0, 4));
            $saldo = $this->calcularSaldoReal($db, (int) $pedido['funcionario_id'], $ano, $id);

            if ($pedido['dias_uteis'] > $saldo['disponivel_sem_este_pedido']) {
                return $this->json(409, [
                    'erro'     => true,
                    'mensagem' => "Não é possível aprovar: saldo insuficiente. Disponível: {$saldo['disponivel_sem_este_pedido']} dias.",
                ]);
            }

            $db->prepare("
                UPDATE ferias_pedidos
                SET estado = 'aprovado_rh', aprovado_rh_por = :uid, data_aprovacao_final = NOW()
                WHERE id = :id
            ")->execute([':uid' => (int) $user->sub, ':id' => $id]);

            // Descontar dias do saldo
            $db->prepare("
                UPDATE ferias
                SET dias_gozados = dias_gozados + :dias,
                    dias_pendentes = GREATEST(dias_pendentes - :dias, 0)
                WHERE funcionario_id = :fid AND ano = :ano
            ")->execute([
                ':dias' => $pedido['dias_uteis'],
                ':fid'  => $pedido['funcionario_id'],
                ':ano'  => $ano,
            ]);

            return $this->json(200, ['mensagem' => 'Férias aprovadas com sucesso.']);
        }

        return $this->json(403, ['erro' => true, 'mensagem' => 'Sem permissão para esta operação no estado actual do pedido.']);
    }

    /**
     * PUT /api/ferias/{funcionario_id}/ajustar
     * Ajuste de saldo por faltas injustificadas (Lei 12/23)
     * Reduz dias de direito mas garante mínimo de 12 dias úteis
     */
    public function ajustarPorFaltas(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $fid  = (int) $args['funcionario_id'];
        $body = $request->getParsedBody() ?? [];
        $db   = $this->db();

        $ano           = (int) ($body['ano'] ?? date('Y'));
        $faltasInj     = (int) ($body['faltas_injustificadas'] ?? 0);
        $motivoAjuste  = $body['motivo'] ?? 'Ajuste por faltas injustificadas';

        if ($faltasInj <= 0) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Número de faltas deve ser positivo.']);
        }

        $stmt = $db->prepare("SELECT * FROM ferias WHERE funcionario_id = :fid AND ano = :ano LIMIT 1");
        $stmt->execute([':fid' => $fid, ':ano' => $ano]);
        $ferias = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ferias) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Registo de férias não encontrado para este ano.']);
        }

        // Lei 12/23: cada falta injustificada reduz 1 dia de férias
        // Mas o mínimo garantido é 12 dias úteis
        $diasActuais  = (int) $ferias['dias_direito'];
        $reducao      = min($faltasInj, $diasActuais - 12); // nunca desce abaixo de 12
        $reducao      = max(0, $reducao);
        $novosDireito = $diasActuais - $reducao;
        $novosPendentes = max(0, (int) $ferias['dias_pendentes'] - $reducao);

        $db->prepare("
            UPDATE ferias
            SET dias_direito = :direito, dias_pendentes = :pendentes
            WHERE funcionario_id = :fid AND ano = :ano
        ")->execute([
            ':direito'   => $novosDireito,
            ':pendentes' => $novosPendentes,
            ':fid'       => $fid,
            ':ano'       => $ano,
        ]);

        // Log de auditoria
        $db->prepare("
            INSERT INTO log_auditoria (accao, entidade, entidade_id, dados_antes, dados_depois, ip)
            VALUES ('ferias.ajuste_faltas', 'ferias', :fid, :antes, :depois, :ip)
        ")->execute([
            ':fid'   => $fid,
            ':antes' => json_encode(['dias_direito' => $diasActuais, 'dias_pendentes' => $ferias['dias_pendentes']]),
            ':depois' => json_encode(['dias_direito' => $novosDireito, 'dias_pendentes' => $novosPendentes, 'faltas_injustificadas' => $faltasInj, 'motivo' => $motivoAjuste]),
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        return $this->json(200, [
            'mensagem'        => "Saldo ajustado. Redução de {$reducao} dia(s) por {$faltasInj} falta(s) injustificada(s).",
            'dias_direito_anterior' => $diasActuais,
            'dias_direito_novo'     => $novosDireito,
            'reducao_aplicada'      => $reducao,
            'minimo_legal'          => 12,
            'nota_legal'            => 'Lei n.º 12/23, de 27 de Dezembro — mínimo garantido: 12 dias úteis.',
        ]);
    }

    /**
     * Calcula saldo real disponível deduzindo pedidos activos
     */
    private function calcularSaldoReal(PDO $db, int $fid, int $ano, int $excluirPedidoId = 0): array
    {
        $saldo = $db->prepare("SELECT * FROM ferias WHERE funcionario_id = :fid AND ano = :ano LIMIT 1");
        $saldo->execute([':fid' => $fid, ':ano' => $ano]);
        $s = $saldo->fetch(PDO::FETCH_ASSOC);

        if (!$s) {
            return ['dias_direito' => 0, 'dias_gozados' => 0, 'dias_pendentes' => 0, 'dias_em_pedidos_activos' => 0, 'disponivel_real' => 0, 'disponivel_sem_este_pedido' => 0];
        }

        // Somar dias de pedidos activos (pendente ou aprovado_supervisor)
        $pedidosActivos = $db->prepare("
            SELECT COALESCE(SUM(dias_uteis), 0)
            FROM ferias_pedidos
            WHERE funcionario_id = :fid
              AND YEAR(data_inicio) = :ano
              AND estado IN ('pendente', 'aprovado_supervisor')
              AND id != :excluir
        ");
        $pedidosActivos->execute([':fid' => $fid, ':ano' => $ano, ':excluir' => $excluirPedidoId]);
        $diasEmPedidos = (int) $pedidosActivos->fetchColumn();

        $disponivelReal = max(0, (int) $s['dias_pendentes'] - $diasEmPedidos);

        return [
            'dias_direito'              => (int) $s['dias_direito'],
            'dias_gozados'              => (int) $s['dias_gozados'],
            'dias_pendentes'            => (int) $s['dias_pendentes'],
            'dias_em_pedidos_activos'   => $diasEmPedidos,
            'disponivel_real'           => $disponivelReal,
            'disponivel_sem_este_pedido' => max(0, (int) $s['dias_pendentes'] - $diasEmPedidos),
            'minimo_legal'              => 12,
            'nota_legal'                => 'Lei n.º 12/23, de 27 de Dezembro',
        ];
    }

    private function calcularDiasUteis(PDO $db, string $inicio, string $fim): int
    {
        $feriados = $db->query("
            SELECT data FROM feriados
            WHERE data BETWEEN '{$inicio}' AND '{$fim}' AND meio_dia = 0
        ")->fetchAll(PDO::FETCH_COLUMN);
        $feriadosSet = array_flip($feriados);

        $dias  = 0;
        $atual = strtotime($inicio);
        $fimTs = strtotime($fim);

        while ($atual <= $fimTs) {
            $diaSemana = (int) date('N', $atual);
            $dataStr   = date('Y-m-d', $atual);
            if ($diaSemana <= 5 && !isset($feriadosSet[$dataStr])) {
                $dias++;
            }
            $atual = strtotime('+1 day', $atual);
        }

        return $dias;
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
