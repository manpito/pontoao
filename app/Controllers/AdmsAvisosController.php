<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AdmsAvisosController
{
    public function listar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $request->getAttribute('tenant_db');

        $stmt = $db->query("
            SELECT id, tipo, sn_relogio, numero_funcionario, payload_bruto, resolvido, criado_em
            FROM adms_avisos
            WHERE resolvido = 0
            ORDER BY criado_em DESC
        ");

        $avisos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode([
            'dados' => $avisos,
            'total' => count($avisos)
        ], JSON_UNESCAPED_UNICODE));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    public function resolver(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $db = $request->getAttribute('tenant_db');
        $id = (int) $args['id'];

        $stmt = $db->prepare("UPDATE adms_avisos SET resolvido = 1 WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            $response->getBody()->write(json_encode(['erro' => true, 'mensagem' => 'Aviso não encontrado.']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['sucesso' => true, 'mensagem' => 'Aviso resolvido.']));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
