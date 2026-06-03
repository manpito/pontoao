<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * LogAuditoriaController — Consulta de logs de segurança e auditoria
 */
class LogAuditoriaController
{
    /**
     * GET /api/logs/auditoria
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);
        $queryParams = $request->getQueryParams();

        $dataInicio   = $queryParams['data_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
        $dataFim      = $queryParams['data_fim'] ?? date('Y-m-d');
        $utilizadorId = $queryParams['utilizador_id'] ?? null;
        $accao        = $queryParams['accao'] ?? null;
        $entidade     = $queryParams['entidade'] ?? null;
        $ip           = $queryParams['ip'] ?? null;
        $page         = max(1, (int) ($queryParams['page'] ?? 1));
        $perPage      = min(200, max(1, (int) ($queryParams['per_page'] ?? 50)));
        $offset       = ($page - 1) * $perPage;

        $where = ["DATE(l.criado_em) BETWEEN :inicio AND :fim"];
        $params = [
            ':inicio' => $dataInicio,
            ':fim'    => $dataFim
        ];

        if ($utilizadorId !== null && $utilizadorId !== '') {
            $where[] = "l.utilizador_id = :utilizador_id";
            $params[':utilizador_id'] = (int) $utilizadorId;
        }
        if ($accao) {
            $where[] = "l.accao = :accao";
            $params[':accao'] = $accao;
        }
        if ($entidade) {
            $where[] = "l.entidade = :entidade";
            $params[':entidade'] = $entidade;
        }
        if ($ip) {
            $where[] = "l.ip = :ip";
            $params[':ip'] = $ip;
        }

        $whereSql = implode(" AND ", $where);

        // Total count
        $stmtTotal = $db->prepare("SELECT COUNT(*) FROM log_auditoria l WHERE $whereSql");
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        // Data
        $sql = "
            SELECT
                l.*,
                u.nome as utilizador_nome,
                u.email as utilizador_email
            FROM log_auditoria l
            LEFT JOIN utilizadores u ON l.utilizador_id = u.id
            WHERE $whereSql
            ORDER BY l.criado_em DESC
            LIMIT $perPage OFFSET $offset
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format names and JSON
        foreach ($dados as &$log) {
            if (!$log['utilizador_id']) {
                $log['utilizador_nome'] = "Sistema";
                $log['utilizador_email'] = "";
            }

            // Garantir que JSON é retornado como objecto/array
            if (isset($log['dados_antes']) && is_string($log['dados_antes'])) {
                $log['dados_antes'] = json_decode($log['dados_antes'], true);
            }
            if (isset($log['dados_depois']) && is_string($log['dados_depois'])) {
                $log['dados_depois'] = json_decode($log['dados_depois'], true);
            }
        }

        return $this->json($response, 200, [
            'dados'       => $dados,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ]);
    }

    /**
     * GET /api/logs/auditoria/acoes
     */
    public function acoes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->query("SELECT DISTINCT accao FROM log_auditoria WHERE accao IS NOT NULL AND accao != '' ORDER BY accao ASC");
        $dados = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $this->json($response, 200, ['dados' => $dados]);
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
