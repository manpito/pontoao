<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class ConfiguracaoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/configuracoes
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $stmt = $this->db()->query("SELECT chave, valor, tipo, descricao FROM configuracoes ORDER BY chave ASC");

        $configs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $configs[$row['chave']] = [
                'valor'    => $this->castValor($row['valor'], $row['tipo']),
                'tipo'     => $row['tipo'],
                'descricao' => $row['descricao'],
            ];
        }

        return $this->json(200, ['dados' => $configs]);
    }

    /**
     * PUT /api/configuracoes
     * Body: {"chave": "valor", "chave2": "valor2"}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $db   = $this->db();

        if (empty($body)) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Nenhuma configuração fornecida.']);
        }

        // Chaves protegidas que não podem ser alteradas via API
        $protegidas = ['timezone', 'moeda'];

        $stmt = $db->prepare("UPDATE configuracoes SET valor = :valor WHERE chave = :chave");
        $actualizadas = 0;

        foreach ($body as $chave => $valor) {
            if (in_array($chave, $protegidas)) {
                continue;
            }
            $stmt->execute([':valor' => (string) $valor, ':chave' => $chave]);
            $actualizadas += $stmt->rowCount();
        }

        return $this->json(200, [
            'mensagem'     => 'Configurações actualizadas.',
            'actualizadas' => $actualizadas,
        ]);
    }

    private function castValor(?string $valor, string $tipo): mixed
    {
        if ($valor === null) return null;
        return match ($tipo) {
            'integer' => (int) $valor,
            'boolean' => $valor === '1' || $valor === 'true',
            'json'    => json_decode($valor, true),
            default   => $valor,
        };
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
