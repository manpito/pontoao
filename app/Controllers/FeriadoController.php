<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use App\Services\FeriadoService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class FeriadoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve();
        return Database::tenant($sub);
    }

    private function service(): FeriadoService
    {
        return new FeriadoService($this->db());
    }

    /**
     * GET /api/feriados
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        if (isset($params['inicio']) && isset($params['fim'])) {
            $dados = $this->service()->listarFeriadosEntre($params['inicio'], $params['fim']);
        } else {
            $ano = (int) ($params['ano'] ?? date('Y'));
            $dados = $this->service()->listarFeriadosEntre("{$ano}-01-01", "{$ano}-12-31");
        }

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * POST /api/feriados
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];

        if (empty($body['nome']) || empty($body['data'])) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Os campos nome e data são obrigatórios.']);
        }

        $db = $this->db();
        $stmt = $db->prepare("
            INSERT INTO feriados (nome, data, tipo, meio_dia, recorrente, ano)
            VALUES (:nome, :data, :tipo, :meio_dia, :recorrente, :ano)
        ");

        $recorrente = (int) ($body['recorrente'] ?? 0);
        $ano = $recorrente ? null : (int) date('Y', strtotime($body['data']));

        $stmt->execute([
            ':nome'       => $body['nome'],
            ':data'       => $body['data'],
            ':tipo'       => $body['tipo'] ?? 'empresa',
            ':meio_dia'   => (int) ($body['meio_dia'] ?? 0),
            ':recorrente' => $recorrente,
            ':ano'        => $ano,
        ]);

        return $this->json($response, 201, [
            'mensagem' => 'Feriado criado com sucesso.',
            'id'       => (int) $db->lastInsertId(),
        ]);
    }

    /**
     * PUT /api/feriados/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];

        if (empty($body['nome']) || empty($body['data'])) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Os campos nome e data são obrigatórios.']);
        }

        $db = $this->db();
        $stmt = $db->prepare("
            UPDATE feriados
            SET nome = :nome, data = :data, tipo = :tipo, meio_dia = :meio_dia, recorrente = :recorrente, ano = :ano
            WHERE id = :id
        ");

        $recorrente = (int) ($body['recorrente'] ?? 0);
        $ano = $recorrente ? null : (int) date('Y', strtotime($body['data']));

        $stmt->execute([
            ':nome'       => $body['nome'],
            ':data'       => $body['data'],
            ':tipo'       => $body['tipo'] ?? 'empresa',
            ':meio_dia'   => (int) ($body['meio_dia'] ?? 0),
            ':recorrente' => $recorrente,
            ':ano'        => $ano,
            ':id'         => $id,
        ]);

        return $this->json($response, 200, ['mensagem' => 'Feriado actualizado com sucesso.']);
    }

    /**
     * DELETE /api/feriados/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $stmt = $this->db()->prepare("DELETE FROM feriados WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Feriado não encontrado.']);
        }

        return $this->json($response, 200, ['mensagem' => 'Feriado eliminado com sucesso.']);
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
