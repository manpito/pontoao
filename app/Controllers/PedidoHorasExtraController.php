<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class PedidoHorasExtraController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    /**
     * GET /api/pedidos-horas-extra
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $user = $request->getAttribute('auth_user');
        $db = $this->db();

        $where = [];
        $bind = [];

        // RBAC
        if ($user->perfil === 'funcionario') {
            $where[] = 'phe.funcionario_id = :fid';
            $bind[':fid'] = $user->funcionario_id;
        } elseif ($user->perfil === 'supervisor') {
            $where[] = '(f.supervisor_id = :sid OR f.id = :sid_self)';
            $bind[':sid'] = $user->funcionario_id;
            $bind[':sid_self'] = $user->funcionario_id;
        } else {
            // rh_colaborador, rh_manager, super_admin_tenant see all
            if (!empty($params['funcionario_id'])) {
                $where[] = 'phe.funcionario_id = :fid';
                $bind[':fid'] = $params['funcionario_id'];
            }
        }

        if (!empty($params['estado'])) {
            $where[] = 'phe.estado = :estado';
            $bind[':estado'] = $params['estado'];
        }

        if (!empty($params['data_inicio'])) {
            $where[] = 'phe.data >= :data_inicio';
            $bind[':data_inicio'] = $params['data_inicio'];
        }

        if (!empty($params['data_fim'])) {
            $where[] = 'phe.data <= :data_fim';
            $bind[':data_fim'] = $params['data_fim'];
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("
            SELECT
                phe.*,
                f.nome_completo,
                f.numero_funcionario
            FROM pedidos_horas_extra phe
            JOIN funcionarios f ON phe.funcionario_id = f.id
            $whereStr
            ORDER BY phe.data DESC, phe.id DESC
        ");
        $stmt->execute($bind);

        return $this->json(200, ['dados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * POST /api/pedidos-horas-extra
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $user = $request->getAttribute('auth_user');
        $db = $this->db();

        $funcionarioId = $user->funcionario_id;
        if (!$funcionarioId) {
            return $this->json(403, ['erro' => true, 'mensagem' => 'Apenas funcionários podem submeter pedidos.']);
        }

        $data = $body['data'] ?? '';
        $minutos = (int) ($body['minutos'] ?? 0);
        $motivo = $body['motivo'] ?? '';

        if (!$data || $minutos <= 0 || !$motivo) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Data, minutos (>0) e motivo são obrigatórios.']);
        }

        if ($minutos > 240) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'O limite máximo é de 240 minutos (4h).']);
        }

        $tipo = $minutos > 120 ? 'excepcional' : 'normal';

        // Verificar duplicados
        $check = $db->prepare("SELECT id FROM pedidos_horas_extra WHERE funcionario_id = :fid AND data = :data LIMIT 1");
        $check->execute([':fid' => $funcionarioId, ':data' => $data]);
        if ($check->fetch()) {
            return $this->json(409, ['erro' => true, 'mensagem' => 'Já existe um pedido para esta data.']);
        }

        $stmt = $db->prepare("
            INSERT INTO pedidos_horas_extra (funcionario_id, data, minutos, tipo, motivo, estado, submetido_por)
            VALUES (:fid, :data, :minutos, :tipo, :motivo, 'pendente', :submetido_por)
        ");

        $stmt->execute([
            ':fid' => $funcionarioId,
            ':data' => $data,
            ':minutos' => $minutos,
            ':tipo' => $tipo,
            ':motivo' => $motivo,
            ':submetido_por' => $request->getAttribute('auth_user_id')
        ]);

        return $this->json(201, [
            'mensagem' => 'Pedido submetido com sucesso.',
            'id' => $db->lastInsertId()
        ]);
    }

    /**
     * PUT /api/pedidos-horas-extra/{id}/aprovar
     */
    public function aprovar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $user = $request->getAttribute('auth_user');
        $db = $this->db();

        $stmt = $db->prepare("SELECT * FROM pedidos_horas_extra WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pedido) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Pedido não encontrado.']);
        }

        $perfil = $user->perfil;
        $novoEstado = null;
        $updateFields = [];
        $params = [':id' => $id];

        if (in_array($perfil, ['rh_colaborador', 'rh_manager', 'super_admin_tenant'])) {
            if ($pedido['estado'] === 'pendente') {
                if ($pedido['tipo'] === 'normal') {
                    $novoEstado = 'aprovado';
                    $updateFields[] = "aprovado_por = :u_id";
                } else {
                    // excepcional
                    $novoEstado = 'aprovado_rh';
                    $updateFields[] = "aprovado_rh_por = :u_id";
                }
                $params[':u_id'] = $request->getAttribute('auth_user_id');
            } elseif ($pedido['estado'] === 'aprovado_rh' && in_array($perfil, ['rh_manager', 'super_admin_tenant'])) {
                $novoEstado = 'aprovado';
                $updateFields[] = "aprovado_por = :u_id";
                $params[':u_id'] = $request->getAttribute('auth_user_id');
            }
        }

        if (!$novoEstado) {
            return $this->json(403, ['erro' => true, 'mensagem' => 'Sem permissão para aprovar este pedido no estado actual.']);
        }

        $fieldsStr = $updateFields ? implode(', ', $updateFields) . ',' : '';
        $sql = "UPDATE pedidos_horas_extra SET estado = :estado, $fieldsStr data_aprovacao = NOW() WHERE id = :id";
        $params[':estado'] = $novoEstado;

        $db->prepare($sql)->execute($params);

        return $this->json(200, ['mensagem' => "Pedido actualizado para $novoEstado."]);
    }

    /**
     * PUT /api/pedidos-horas-extra/{id}/rejeitar
     */
    public function rejeitar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $user = $request->getAttribute('auth_user');
        $db = $this->db();

        if (!in_array($user->perfil, ['rh_colaborador', 'rh_manager', 'super_admin_tenant'])) {
            return $this->json(403, ['erro' => true, 'mensagem' => 'Sem permissão para rejeitar pedidos.']);
        }

        $stmt = $db->prepare("SELECT estado FROM pedidos_horas_extra WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pedido) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Pedido não encontrado.']);
        }

        if (!in_array($pedido['estado'], ['pendente', 'aprovado_rh'])) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Apenas pedidos pendentes ou em aprovação RH podem ser rejeitados.']);
        }

        $motivo = $body['motivo'] ?? '';
        if (!$motivo) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'O motivo da rejeição é obrigatório.']);
        }

        $db->prepare("
            UPDATE pedidos_horas_extra
            SET estado = 'rejeitado', rejeitado_motivo = :motivo
            WHERE id = :id
        ")->execute([
            ':motivo' => $motivo,
            ':id' => $id
        ]);

        return $this->json(200, ['mensagem' => 'Pedido rejeitado com sucesso.']);
    }

    /**
     * GET /api/pedidos-horas-extra/pendentes
     */
    public function pendentes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('auth_user');
        $db = $this->db();

        $where = ["phe.estado = 'pendente'"];
        $bind = [];

        if ($user->perfil === 'funcionario') {
            $where[] = 'phe.funcionario_id = :fid';
            $bind[':fid'] = $user->funcionario_id;
        } elseif ($user->perfil === 'supervisor') {
            $where[] = '(f.supervisor_id = :sid OR f.id = :sid_self)';
            $bind[':sid'] = $user->funcionario_id;
            $bind[':sid_self'] = $user->funcionario_id;
        }
        // RH see all pending

        $whereStr = 'WHERE ' . implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM pedidos_horas_extra phe
            JOIN funcionarios f ON phe.funcionario_id = f.id
            $whereStr
        ");
        $stmt->execute($bind);

        return $this->json(200, ['total' => (int) $stmt->fetchColumn()]);
    }
}
