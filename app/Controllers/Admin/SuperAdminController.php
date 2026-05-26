<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\TenantManager;
use App\Config\Database;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * SuperAdminController — Dashboard e operações centrais
 */
class SuperAdminController
{
    private TenantManager $tenantManager;

    public function __construct()
    {
        $this->tenantManager = new TenantManager();
    }

    /**
     * GET /super-admin/dashboard
     */
    public function dashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $metrics = $this->tenantManager->getDashboardMetrics();

        return $this->json(200, [
            'metricas' => $metrics,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * GET /super-admin/audit-log
     */
    public function auditLog(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params    = $request->getQueryParams();
        $pagina    = max(1, (int) ($params['pagina'] ?? 1));
        $porPagina = min(100, (int) ($params['por_pagina'] ?? 50));
        $offset    = ($pagina - 1) * $porPagina;

        $where  = '1=1';
        $pdo    = Database::master();
        $sqlParams = [];

        if (!empty($params['tenant_id'])) {
            $where .= ' AND l.`tenant_id` = :tenant_id';
            $sqlParams[':tenant_id'] = (int) $params['tenant_id'];
        }

        if (!empty($params['accao'])) {
            $where .= ' AND l.`accao` LIKE :accao';
            $sqlParams[':accao'] = '%' . $params['accao'] . '%';
        }

        if (!empty($params['data_inicio'])) {
            $where .= ' AND l.`criado_em` >= :data_inicio';
            $sqlParams[':data_inicio'] = $params['data_inicio'];
        }

        if (!empty($params['data_fim'])) {
            $where .= ' AND l.`criado_em` <= :data_fim';
            $sqlParams[':data_fim'] = $params['data_fim'];
        }

        $total = $pdo->prepare("SELECT COUNT(*) FROM `log_auditoria_master` l WHERE {$where}");
        $total->execute($sqlParams);
        $totalRegistos = (int) $total->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT l.*, a.`nome` as admin_nome, t.`nome_empresa`, t.`subdominio`
            FROM `log_auditoria_master` l
            LEFT JOIN `super_admins` a ON l.`super_admin_id` = a.`id`
            LEFT JOIN `tenants` t ON l.`tenant_id` = t.`id`
            WHERE {$where}
            ORDER BY l.`criado_em` DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($sqlParams as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json(200, [
            'dados'      => $logs,
            'paginacao'  => [
                'pagina'      => $pagina,
                'por_pagina'  => $porPagina,
                'total'       => $totalRegistos,
                'total_paginas' => (int) ceil($totalRegistos / $porPagina),
            ],
        ]);
    }

    /**
     * GET /super-admin/planos
     */
    public function planos(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $stmt = Database::master()->query("SELECT * FROM `planos` WHERE `activo` = 1 ORDER BY `preco_mensal_aoa` ASC");
        return $this->json(200, ['planos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
