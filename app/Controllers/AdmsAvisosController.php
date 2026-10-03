<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use App\Config\Database;
use App\Config\TenantResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AdmsAvisosController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    public function listar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();

        $stmt = $db->query("
            SELECT id, tipo, sn_relogio, numero_funcionario, payload_bruto, resolvido, criado_em
            FROM adms_avisos
            WHERE resolvido = 0 AND tipo != 'funcionario_desconhecido'
            ORDER BY criado_em DESC
        ");

        $avisos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode([
            'dados' => $avisos,
            'total' => count($avisos)
        ], JSON_UNESCAPED_UNICODE));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    public function relatorioFuncionarioDesconhecido(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();

        $stmt = $db->query("
            SELECT
                a.sn_relogio,
                r.nome AS relogio_nome,
                r.localizacao AS relogio_localizacao,
                a.numero_funcionario,
                COUNT(*) AS total_ocorrencias,
                MIN(a.criado_em) AS primeira_ocorrencia,
                MAX(a.criado_em) AS ultima_ocorrencia
            FROM adms_avisos a
            LEFT JOIN relogios r ON a.sn_relogio = r.device_id
            WHERE a.tipo = 'funcionario_desconhecido'
            GROUP BY a.sn_relogio, a.numero_funcionario
            ORDER BY ultima_ocorrencia DESC
        ");

        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode([
            'dados' => $dados,
            'total' => count($dados)
        ], JSON_UNESCAPED_UNICODE));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    public function resolver(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $db = $this->db();
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
