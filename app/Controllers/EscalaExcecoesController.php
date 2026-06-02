<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use App\Services\EscalaService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * EscalaExcecoesController — Gestão de excepções e cobertura de escalas
 */
class EscalaExcecoesController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve();
        return Database::tenant($sub);
    }

    /**
     * GET /api/escala-excepcoes
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();
        $params = $request->getQueryParams();
        $user = $request->getAttribute('auth_user');

        $sql = "SELECT exc.*,
                       f_aus.nome_completo as funcionario_ausente_nome,
                       f_aus.numero_funcionario as funcionario_ausente_numero,
                       f_sub.nome_completo as funcionario_substituto_nome,
                       f_sub.numero_funcionario as funcionario_substituto_numero,
                       t.nome as turno_nome
                FROM escala_excepcoes exc
                JOIN funcionarios f_aus ON exc.funcionario_ausente_id = f_aus.id
                LEFT JOIN funcionarios f_sub ON exc.funcionario_substituto_id = f_sub.id
                JOIN turnos t ON exc.turno_id = t.id
                WHERE 1=1";

        $bind = [];

        if (!empty($params['data_inicio'])) {
            $sql .= " AND exc.data >= :data_inicio";
            $bind[':data_inicio'] = $params['data_inicio'];
        }
        if (!empty($params['data_fim'])) {
            $sql .= " AND exc.data <= :data_fim";
            $bind[':data_fim'] = $params['data_fim'];
        }

        if (!empty($params['estado'])) {
            if ($params['estado'] === 'coberto') {
                $sql .= " AND exc.funcionario_substituto_id IS NOT NULL";
            } elseif ($params['estado'] === 'descoberto') {
                $sql .= " AND exc.funcionario_substituto_id IS NULL";
            }
        }

        if ($user && $user->perfil === 'supervisor') {
            $sql .= " AND (f_aus.supervisor_id = :sid OR f_aus.id = :sid)";
            $bind[':sid'] = $user->funcionario_id;
        }

        $stmt = $db->prepare($sql . " ORDER BY exc.data DESC, exc.id DESC");
        $stmt->execute($bind);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * POST /api/escala-excepcoes
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();
        $body = $request->getParsedBody();
        $user = $request->getAttribute('auth_user');

        $data = $body['data'] ?? '';
        $ausenteId = (int) ($body['funcionario_ausente_id'] ?? 0);
        $substitutoId = !empty($body['funcionario_substituto_id']) ? (int) $body['funcionario_substituto_id'] : null;
        $turnoId = (int) ($body['turno_id'] ?? 0);
        $motivo = $body['motivo'] ?? '';
        $descricao = $body['descricao'] ?? null;

        if (!$data || !$ausenteId || !$turnoId || !$motivo) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Data, funcionário ausente, turno e motivo são obrigatórios.']);
        }

        if ($substitutoId !== null && $substitutoId === $ausenteId) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'O substituto não pode ser a mesma pessoa que o funcionário ausente.']);
        }

        // Validar existência do turno
        $stmt = $db->prepare("SELECT id FROM turnos WHERE id = :id");
        $stmt->execute([':id' => $turnoId]);
        if (!$stmt->fetch()) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Turno inválido.']);
        }

        // RBAC adicional para supervisor
        if ($user && $user->perfil === 'supervisor') {
            $stmt = $db->prepare("SELECT id, supervisor_id FROM funcionarios WHERE id = :id");
            $stmt->execute([':id' => $ausenteId]);
            $func = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$func || ($func['supervisor_id'] != $user->funcionario_id && $func['id'] != $user->funcionario_id)) {
                return $this->json($response, 403, ['erro' => true, 'mensagem' => 'Sem permissão para criar excepções para este funcionário.']);
            }
        }

        // Validar se funcionário tem escala activa que inclua esse turno nessa data
        $escalaService = new EscalaService($db);
        $turnoCalculado = $escalaService->calcularTurnoEm($ausenteId, $data);

        if (!$turnoCalculado) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'O funcionário ausente não tem escala activa na data seleccionada.']);
        }

        if ($turnoCalculado['turno_id'] !== $turnoId) {
             return $this->json($response, 400, ['erro' => true, 'mensagem' => 'O turno seleccionado não corresponde ao turno previsto para o funcionário nesta data.']);
        }

        $stmt = $db->prepare("
            INSERT INTO escala_excepcoes (data, funcionario_ausente_id, funcionario_substituto_id, turno_id, motivo, descricao, criado_por)
            VALUES (:data, :ausente, :substituto, :turno, :motivo, :descricao, :criado_por)
        ");

        $stmt->execute([
            ':data'       => $data,
            ':ausente'    => $ausenteId,
            ':substituto' => $substitutoId,
            ':turno'      => $turnoId,
            ':motivo'     => $motivo,
            ':descricao'  => $descricao,
            ':criado_por' => $user->sub
        ]);

        return $this->json($response, 201, ['mensagem' => 'Excepção criada com sucesso.', 'id' => $db->lastInsertId()]);
    }

    /**
     * PUT /api/escala-excepcoes/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $db = $this->db();
        $body = $request->getParsedBody();
        $user = $request->getAttribute('auth_user');

        $stmt = $db->prepare("SELECT * FROM escala_excepcoes WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $exc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$exc) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Excepção não encontrada.']);
        }

        // RBAC adicional para supervisor
        if ($user && $user->perfil === 'supervisor') {
            $stmt = $db->prepare("SELECT supervisor_id FROM funcionarios WHERE id = :id");
            $stmt->execute([':id' => $exc['funcionario_ausente_id']]);
            $func = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$func || ($func['supervisor_id'] != $user->funcionario_id && $exc['funcionario_ausente_id'] != $user->funcionario_id)) {
                return $this->json($response, 403, ['erro' => true, 'mensagem' => 'Sem permissão para alterar excepções deste funcionário.']);
            }
        }

        $substitutoId = isset($body['funcionario_substituto_id']) ? (!empty($body['funcionario_substituto_id']) ? (int) $body['funcionario_substituto_id'] : null) : $exc['funcionario_substituto_id'];
        $descricao = isset($body['descricao']) ? $body['descricao'] : $exc['descricao'];

        if ($substitutoId !== null && $substitutoId === (int)$exc['funcionario_ausente_id']) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'O substituto não pode ser a mesma pessoa que o funcionário ausente.']);
        }

        $stmt = $db->prepare("
            UPDATE escala_excepcoes SET
                funcionario_substituto_id = :substituto,
                descricao = :descricao
            WHERE id = :id
        ");

        $stmt->execute([
            ':substituto' => $substitutoId,
            ':descricao'  => $descricao,
            ':id'          => $id
        ]);

        return $this->json($response, 200, ['mensagem' => 'Excepção actualizada com sucesso.']);
    }

    /**
     * DELETE /api/escala-excepcoes/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $db = $this->db();
        $user = $request->getAttribute('auth_user');

        $stmt = $db->prepare("SELECT * FROM escala_excepcoes WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $exc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$exc) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Excepção não encontrada.']);
        }

        // RBAC adicional para supervisor
        if ($user && $user->perfil === 'supervisor') {
            $stmt = $db->prepare("SELECT supervisor_id FROM funcionarios WHERE id = :id");
            $stmt->execute([':id' => $exc['funcionario_ausente_id']]);
            $func = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$func || ($func['supervisor_id'] != $user->funcionario_id && $exc['funcionario_ausente_id'] != $user->funcionario_id)) {
                return $this->json($response, 403, ['erro' => true, 'mensagem' => 'Sem permissão para eliminar excepções deste funcionário.']);
            }
        }

        $db->prepare("DELETE FROM escala_excepcoes WHERE id = :id")->execute([':id' => $id]);

        return $this->json($response, 200, ['mensagem' => 'Excepção eliminada com sucesso.']);
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
