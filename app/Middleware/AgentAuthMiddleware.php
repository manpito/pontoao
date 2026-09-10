<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use App\Config\Database;

class AgentAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        $tenantId   = $request->getHeaderLine('X-Tenant-ID');

        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return (new Response(401))->withHeader('Content-Type', 'application/json');
        }

        if (empty($tenantId)) {
            return (new Response(401))->withHeader('Content-Type', 'application/json');
        }

        $apiKey = substr($authHeader, 7);
        $hashedApiKey = hash('sha256', $apiKey);

        try {
            $db = Database::tenant($tenantId);
        } catch (\Exception $e) {
            return (new Response(401))->withHeader('Content-Type', 'application/json');
        }

        $stmt = $db->prepare("SELECT api_key_hash FROM relogios WHERE activo = 1");
        $stmt->execute();
        $hashes = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $valid = false;
        foreach ($hashes as $dbHash) {
            if ($dbHash && hash_equals($dbHash, $hashedApiKey)) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            return (new Response(401))->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request->withAttribute('tenant_db', $db));
    }
}
