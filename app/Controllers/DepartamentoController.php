<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class DepartamentoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/departamentos
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $stmt = $this->db()->query("
            SELECT
                d.id, d.nome, d.codigo, d.activo, d.criado_em,
                dp.nome AS departamento_pai,
                u.nome AS responsavel_nome,
                COUNT(f.id) AS total_funcionarios
            FROM departamentos d
            LEFT JOIN departamentos dp ON d.departamento_pai_id = dp.id
            LEFT JOIN utilizadores u ON d.responsavel_id = u.id
            LEFT JOIN funcionarios f ON f.departamento_id = d.id AND f.estado = 'activo'
            GROUP BY d.id
            ORDER BY d.nome ASC
        ");

        return $this->json(200, ['dados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * POST /api/departamentos
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];

        if (empty($body['nome'])) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'O campo nome é obrigatório.']);
        }

        $db   = $this->db();
        $stmt = $db->prepare("
            INSERT INTO departamentos (nome, codigo, departamento_pai_id, responsavel_id)
            VALUES (:nome, :codigo, :pai_id, :resp_id)
        ");
        $stmt->execute([
            ':nome'    => $body['nome'],
            ':codigo'  => $body['codigo'] ?? null,
            ':pai_id'  => !empty($body['departamento_pai_id']) ? (int) $body['departamento_pai_id'] : null,
            ':resp_id' => !empty($body['responsavel_id']) ? (int) $body['responsavel_id'] : null,
        ]);

        return $this->json(201, [
            'mensagem' => 'Departamento criado com sucesso.',
            'id'       => (int) $db->lastInsertId(),
        ]);
    }

    /**
     * PUT /api/departamentos/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $db   = $this->db();

        $stmt = $db->prepare("SELECT id FROM departamentos WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Departamento não encontrado.']);
        }

        $db->prepare("
            UPDATE departamentos
            SET nome = :nome, codigo = :codigo, departamento_pai_id = :pai_id, responsavel_id = :resp_id, activo = :activo
            WHERE id = :id
        ")->execute([
            ':nome'    => $body['nome'],
            ':codigo'  => $body['codigo'] ?? null,
            ':pai_id'  => !empty($body['departamento_pai_id']) ? (int) $body['departamento_pai_id'] : null,
            ':resp_id' => !empty($body['responsavel_id']) ? (int) $body['responsavel_id'] : null,
            ':activo'  => isset($body['activo']) ? (int) $body['activo'] : 1,
            ':id'      => $id,
        ]);

        return $this->json(200, ['mensagem' => 'Departamento actualizado com sucesso.']);
    }

    /**
     * DELETE /api/departamentos/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $db = $this->db();

        // Verificar se tem funcionários activos
        $count = $db->prepare("SELECT COUNT(*) FROM funcionarios WHERE departamento_id = :id AND estado = 'activo'");
        $count->execute([':id' => $id]);
        if ((int) $count->fetchColumn() > 0) {
            return $this->json(409, ['erro' => true, 'mensagem' => 'Não é possível eliminar um departamento com funcionários activos.']);
        }

        $db->prepare("UPDATE departamentos SET activo = 0 WHERE id = :id")->execute([':id' => $id]);

        return $this->json(200, ['mensagem' => 'Departamento desactivado com sucesso.']);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
