<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class FeriadoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/feriados
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $ano    = (int) ($params['ano'] ?? date('Y'));

        $stmt = $this->db()->prepare("
            SELECT id, nome, data, tipo, meio_dia, recorrente
            FROM feriados
            WHERE YEAR(data) = :ano OR recorrente = 1
            ORDER BY data ASC
        ");
        $stmt->execute([':ano' => $ano]);

        return $this->json(200, ['dados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * POST /api/feriados
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];

        if (empty($body['nome']) || empty($body['data'])) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Os campos nome e data são obrigatórios.']);
        }

        $db   = $this->db();
        $stmt = $db->prepare("
            INSERT IGNORE INTO feriados (nome, data, tipo, meio_dia, recorrente)
            VALUES (:nome, :data, :tipo, :meio_dia, :recorrente)
        ");
        $stmt->execute([
            ':nome'       => $body['nome'],
            ':data'       => $body['data'],
            ':tipo'       => $body['tipo'] ?? 'empresa',
            ':meio_dia'   => (int) ($body['meio_dia'] ?? 0),
            ':recorrente' => (int) ($body['recorrente'] ?? 0),
        ]);

        return $this->json(201, [
            'mensagem' => 'Feriado criado com sucesso.',
            'id'       => (int) $db->lastInsertId(),
        ]);
    }

    /**
     * DELETE /api/feriados/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $stmt = $this->db()->prepare("DELETE FROM feriados WHERE id = :id AND tipo = 'empresa'");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Feriado não encontrado ou não pode ser eliminado (apenas feriados de empresa podem ser removidos).']);
        }

        return $this->json(200, ['mensagem' => 'Feriado eliminado com sucesso.']);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
