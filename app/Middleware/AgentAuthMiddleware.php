<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Autenticação do agente PontoAO (sync PontoAO -> relógios Dahua).
 *
 * Cada instalação do agente tem uma chave própria, ligada a um tenant na tabela
 * agent_chaves da base master (só se guarda o sha256). O tenant é resolvido pela
 * chave; o cabeçalho X-Tenant-ID tem de coincidir com o subdomínio desse tenant.
 * Chaves revogadas são recusadas.
 */
class AgentAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        $tenantId   = trim($request->getHeaderLine('X-Tenant-ID'));

        if (!str_starts_with($authHeader, 'Bearer ') || $tenantId === '') {
            return $this->negar();
        }

        $apiKey = trim(substr($authHeader, 7));
        if ($apiKey === '') {
            return $this->negar();
        }

        try {
            $master = $this->master();
            $stmt = $master->prepare("
                SELECT ac.id, t.subdominio
                FROM agent_chaves ac
                JOIN tenants t ON t.id = ac.tenant_id
                WHERE ac.chave_hash = :hash
                  AND ac.revogada_em IS NULL
                LIMIT 1
            ");
            $stmt->execute([':hash' => hash('sha256', $apiKey)]);
            $chave = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return $this->negar();
        }

        if (!$chave || !hash_equals((string) $chave['subdominio'], $tenantId)) {
            return $this->negar();
        }

        try {
            $master->prepare("UPDATE agent_chaves SET ultimo_uso_em = :agora WHERE id = :id")
                   ->execute([':agora' => date('Y-m-d H:i:s'), ':id' => $chave['id']]);
        } catch (\Throwable $e) {
            // O registo de uso é auxiliar: não bloqueia o pedido.
        }

        try {
            $db = $this->abrirTenant((string) $chave['subdominio']);
        } catch (\Throwable $e) {
            return $this->negar();
        }

        return $handler->handle($request->withAttribute('tenant_db', $db));
    }

    protected function master(): PDO
    {
        return Database::master();
    }

    protected function abrirTenant(string $subdominio): PDO
    {
        return Database::tenant($subdominio);
    }

    private function negar(): ResponseInterface
    {
        return (new Response(401))->withHeader('Content-Type', 'application/json');
    }
}