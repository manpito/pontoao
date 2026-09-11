<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use PDO;

class AgentController
{
    private function json(Response $response, int $status, array $data): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    public function getFuncionarios(Request $request, Response $response): Response
    {
        $db = $request->getAttribute('tenant_db');

        $stmt = $db->query("
            SELECT
                f.id,
                f.numero_funcionario,
                f.nome_completo,
                f.departamento_id,
                f.estado,
                f.actualizado_em AS atualizado_em
            FROM funcionarios f
            WHERE f.estado IN ('activo', 'inactivo')
            ORDER BY f.numero_funcionario ASC
        ");

        $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map types slightly if needed, e.g., ints
        foreach ($funcionarios as &$func) {
            $func['id'] = (int) $func['id'];
            $func['departamento_id'] = $func['departamento_id'] ? (int) $func['departamento_id'] : null;
            $func['atualizado_em'] = $func['atualizado_em'] ?? null;
        }

        return $this->json($response, 200, ['funcionarios' => $funcionarios]);
    }

    public function getRelogios(Request $request, Response $response): Response
    {
        $db = $request->getAttribute('tenant_db');

        $stmt = $db->query("
            SELECT
                r.id,
                r.nome,
                r.device_url,
                r.device_user,
                r.device_password_enc,
                r.tipo_protocolo,
                GROUP_CONCAT(rd.departamento_id) AS departamentos
            FROM relogios r
            LEFT JOIN relogio_departamentos rd ON rd.relogio_id = r.id
            WHERE r.tipo_protocolo = 'dahua'
              AND r.activo = 1
            GROUP BY r.id
        ");

        $relogios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $appKey = $_ENV['APP_KEY'] ?? '';

        foreach ($relogios as &$relogio) {
            $relogio['id'] = (int) $relogio['id'];

            if ($relogio['device_password_enc'] && $appKey) {
                $relogio['device_password'] = openssl_decrypt(
                    $relogio['device_password_enc'],
                    'aes-256-cbc',
                    $appKey,
                    0,
                    substr($appKey, 0, 16)
                );
            } else {
                $relogio['device_password'] = null;
            }

            unset($relogio['device_password_enc']);

            if ($relogio['departamentos']) {
                $deps = explode(',', $relogio['departamentos']);
                $relogio['departamentos'] = array_map('intval', $deps);
            } else {
                $relogio['departamentos'] = [];
            }
        }

        return $this->json($response, 200, ['relogios' => $relogios]);
    }

    public function postSyncLog(Request $request, Response $response): Response
    {
        $db = $request->getAttribute('tenant_db');

        $contentType = $request->getHeaderLine('Content-Type');
        $body = [];
        if (str_contains($contentType, 'application/json')) {
            $body = $request->getParsedBody() ?? [];
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO agent_sync_log
                (relogio_id, funcionario_id, operacao, sucesso, erro, timestamp)
                VALUES
                (:relogio_id, :funcionario_id, :operacao, :sucesso, :erro, :timestamp)
            ");

            $stmt->execute([
                ':relogio_id'     => $body['relogio_id'] ?? 0,
                ':funcionario_id' => $body['funcionario_id'] ?? 0,
                ':operacao'       => $body['operacao'] ?? 'insert',
                ':sucesso'        => (isset($body['sucesso']) && $body['sucesso']) ? 1 : 0,
                ':erro'           => $body['erro'] ?? null,
                ':timestamp'      => $body['timestamp'] ?? date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Ignore error
        }

        return $this->json($response, 200, ['result' => 'ok']);
    }
}
