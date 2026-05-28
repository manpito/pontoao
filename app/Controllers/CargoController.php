<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * CargoController — Gestão de cargos (títulos profissionais)
 */
class CargoController
{
    /**
     * GET /api/cargos
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->query("
            SELECT c.*, d.nome AS departamento_nome
            FROM cargos c
            LEFT JOIN departamentos d ON c.departamento_id = d.id
            ORDER BY c.nome ASC
        ");
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * POST /api/cargos
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub  = TenantResolver::resolve();
        $db   = Database::tenant($sub);
        $body = $request->getParsedBody();

        $nome = trim($body['nome'] ?? '');
        if (empty($nome)) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'O nome do cargo é obrigatório.']);
        }

        $stmt = $db->prepare("
            INSERT INTO cargos (nome, categoria_profissional, departamento_id)
            VALUES (:nome, :categoria, :dep_id)
        ");

        $stmt->execute([
            ':nome'      => $nome,
            ':categoria' => $body['categoria_profissional'] ?? null,
            ':dep_id'    => $body['departamento_id'] ? (int) $body['departamento_id'] : null
        ]);

        return $this->json($response, 201, ['mensagem' => 'Cargo criado com sucesso.', 'id' => $db->lastInsertId()]);
    }

    /**
     * PUT /api/cargos/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $sub  = TenantResolver::resolve();
        $db   = Database::tenant($sub);
        $body = $request->getParsedBody();

        $nome = trim($body['nome'] ?? '');
        if (empty($nome)) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'O nome do cargo é obrigatório.']);
        }

        $stmt = $db->prepare("
            UPDATE cargos
            SET nome = :nome, categoria_profissional = :categoria, departamento_id = :dep_id
            WHERE id = :id
        ");

        $stmt->execute([
            ':nome'      => $nome,
            ':categoria' => $body['categoria_profissional'] ?? null,
            ':dep_id'    => $body['departamento_id'] ? (int) $body['departamento_id'] : null,
            ':id'        => $id
        ]);

        return $this->json($response, 200, ['mensagem' => 'Cargo actualizado com sucesso.']);
    }

    /**
     * DELETE /api/cargos/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        // Verificar se existem funcionários com este cargo
        $stmt = $db->prepare("SELECT COUNT(*) FROM funcionarios WHERE cargo_id = :id");
        $stmt->execute([':id' => $id]);
        if ($stmt->fetchColumn() > 0) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Não pode eliminar um cargo que tem funcionários associados.']);
        }

        $db->prepare("DELETE FROM cargos WHERE id = :id")->execute([':id' => $id]);

        return $this->json($response, 200, ['mensagem' => 'Cargo eliminado com sucesso.']);
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
