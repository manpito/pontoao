<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Middleware de autenticação JWT para rotas do tenant
 *
 * Valida o Bearer token no header Authorization
 * Injeta o payload do utilizador no atributo 'auth_user' do request
 */
class AuthMiddleware implements MiddlewareInterface
{
    private array $rolesPermitidos;

    public function __construct(array $roles = [])
    {
        $this->rolesPermitidos = $roles;
    }

    public static function role(array $roles): self
    {
        return new self($roles);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            return $this->unauthorized('Token de autenticação em falta.');
        }

        $authService = new AuthService();
        $token = $authService->extractBearerToken($authHeader);

        if (!$token) {
            return $this->unauthorized('Formato de token inválido. Use: Bearer <token>');
        }

        try {
            $payload = $authService->validateAccessToken($token);
        } catch (\RuntimeException $e) {
            return $this->unauthorized($e->getMessage());
        }

        // Verificar perfil se especificado
        if (!empty($this->rolesPermitidos) && !in_array($payload->perfil, $this->rolesPermitidos, true)) {
            return $this->forbidden('Sem permissão para aceder a este recurso.');
        }

        // Injectar dados do utilizador no request
        $request = $request
            ->withAttribute('auth_user', $payload)
            ->withAttribute('auth_user_id', (int) $payload->sub)
            ->withAttribute('auth_perfil', $payload->perfil);

        return $handler->handle($request);
    }

    private function unauthorized(string $mensagem): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(json_encode([
            'erro'    => true,
            'codigo'  => 'NAO_AUTENTICADO',
            'mensagem' => $mensagem,
        ]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', 'Bearer realm="assiduidade"');
    }

    private function forbidden(string $mensagem): ResponseInterface
    {
        $response = new Response(403);
        $response->getBody()->write(json_encode([
            'erro'    => true,
            'codigo'  => 'SEM_PERMISSAO',
            'mensagem' => $mensagem,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
