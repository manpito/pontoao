<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class SuperAdminMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader  = $request->getHeaderLine('Authorization');
        $authService = new AuthService();
        $token       = $authService->extractBearerToken($authHeader);

        if (!$token) {
            return $this->json(401, 'NAO_AUTENTICADO', 'Autenticação necessária.');
        }

        try {
            $payload = $authService->validateAccessToken($token);
        } catch (\RuntimeException $e) {
            return $this->json(401, 'NAO_AUTENTICADO', $e->getMessage());
        }

        if ($payload->perfil !== 'super_admin' || !empty($payload->tenant)) {
            return $this->json(403, 'SEM_PERMISSAO', 'Acesso restrito a super-administradores.');
        }

        return $handler->handle(
            $request->withAttribute('auth_super_admin', $payload)
                    ->withAttribute('auth_super_admin_id', (int) $payload->sub)
        );
    }

    private function json(int $status, string $codigo, string $mensagem): ResponseInterface
    {
        $r = new Response($status);
        $r->getBody()->write(json_encode(['erro' => true, 'codigo' => $codigo, 'mensagem' => $mensagem]));
        return $r->withHeader('Content-Type', 'application/json');
    }
}
