<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class TipoJustificacaoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $db = $this->db();

        $query = "SELECT * FROM tipos_justificacao";

        if (isset($params['activo']) && $params['activo'] === '1') {
            $query .= " WHERE activo = 1";
        }

        $query .= " ORDER BY id ASC";

        $stmt = $db->query($query);
        $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode(['erro' => false, 'dados' => $tipos]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $request->getParsedBody();
        $db = $this->db();

        $nome = trim($data['nome'] ?? '');
        $comportamento = $data['comportamento'] ?? '';

        if (empty($nome) || !in_array($comportamento, ['trabalho', 'falta_justificada'])) {
            $response->getBody()->write(json_encode(['erro' => true, 'mensagem' => 'Dados inválidos.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Generate a safe unique code
        $codigo = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $nome));
        $codigo = trim($codigo, '_');

        // Ensure uniqueness
        $stmt = $db->prepare("SELECT COUNT(*) FROM tipos_justificacao WHERE codigo = ?");
        $stmt->execute([$codigo]);
        if ($stmt->fetchColumn() > 0) {
            $codigo = $codigo . '_' . time();
        }

        $stmt = $db->prepare("INSERT INTO tipos_justificacao (codigo, nome, comportamento, activo) VALUES (?, ?, ?, 1)");
        $stmt->execute([$codigo, $nome, $comportamento]);

        $response->getBody()->write(json_encode(['erro' => false, 'mensagem' => 'Motivo de justificação criado com sucesso.']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)$args['id'];
        $data = json_decode((string)$request->getBody(), true);
        $db = $this->db();

        if (empty($data)) {
            $response->getBody()->write(json_encode(['erro' => true, 'mensagem' => 'Dados inválidos.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $updates = [];
        $params = [];

        if (isset($data['nome'])) {
            $updates[] = "nome = ?";
            $params[] = trim($data['nome']);
        }

        if (isset($data['activo'])) {
            $updates[] = "activo = ?";
            $params[] = (int)$data['activo'];
        }

        if (empty($updates)) {
            $response->getBody()->write(json_encode(['erro' => false, 'mensagem' => 'Sem alterações.']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $params[] = $id;

        $stmt = $db->prepare("UPDATE tipos_justificacao SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);

        $response->getBody()->write(json_encode(['erro' => false, 'mensagem' => 'Motivo de justificação atualizado.']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
