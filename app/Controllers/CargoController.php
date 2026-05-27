<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class CargoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $stmt = $this->db()->query("SELECT c.*, d.nome as departamento_nome
                                   FROM cargos c
                                   LEFT JOIN departamentos d ON c.departamento_id = d.id
                                   ORDER BY c.nome ASC");
        return $this->json(200, ['dados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (empty($body['nome'])) return $this->json(422, ['erro' => true, 'mensagem' => 'Nome é obrigatório.']);

        $db = $this->db();
        $stmt = $db->prepare("INSERT INTO cargos (nome, categoria_profissional, departamento_id) VALUES (:nome, :cat, :did)");
        $stmt->execute([
            ':nome' => $body['nome'],
            ':cat'  => $body['categoria_profissional'] ?? null,
            ':did'  => $body['departamento_id'] ?? null
        ]);

        return $this->json(201, ['mensagem' => 'Cargo criado com sucesso.', 'id' => $db->lastInsertId()]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)$args['id'];
        $body = $request->getParsedBody();
        if (empty($body['nome'])) return $this->json(422, ['erro' => true, 'mensagem' => 'Nome é obrigatório.']);

        $db = $this->db();
        $stmt = $db->prepare("UPDATE cargos SET nome = :nome, categoria_profissional = :cat, departamento_id = :did WHERE id = :id");
        $stmt->execute([
            ':nome' => $body['nome'],
            ':cat'  => $body['categoria_profissional'] ?? null,
            ':did'  => $body['departamento_id'] ?? null,
            ':id'   => $id
        ]);

        return $this->json(200, ['mensagem' => 'Cargo actualizado com sucesso.']);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)$args['id'];
        $db = $this->db();

        // Verificar se há funcionários com este cargo
        $check = $db->prepare("SELECT COUNT(*) FROM funcionarios WHERE cargo_id = :id");
        $check->execute([':id' => $id]);
        if ((int)$check->fetchColumn() > 0) {
            return $this->json(409, ['erro' => true, 'mensagem' => 'Não é possível eliminar este cargo pois existem funcionários associados.']);
        }

        $db->prepare("DELETE FROM cargos WHERE id = :id")->execute([':id' => $id]);
        return $this->json(200, ['mensagem' => 'Cargo eliminado com sucesso.']);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
