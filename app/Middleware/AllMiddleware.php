<?php declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

// ============================================================
// SuperAdminMiddleware — Protege rotas do painel central
// ============================================================

class SuperAdminMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        $authService = new AuthService();
        $token = $authService->extractBearerToken($authHeader);

        if (!$token) {
            return $this->json(401, 'NAO_AUTENTICADO', 'Autenticação necessária.');
        }

        try {
            $payload = $authService->validateAccessToken($token);
        } catch (\RuntimeException $e) {
            return $this->json(401, 'NAO_AUTENTICADO', $e->getMessage());
        }

        // Super-admins têm perfil 'super_admin' e tenant vazio
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

// ============================================================
// TenantMiddleware — Valida que o request é de um tenant válido
// ============================================================

class TenantMiddleware implements MiddlewareInterface
{
    private bool $apenasAdmin;

    public function __construct(bool $apenasAdmin = false)
    {
        $this->apenasAdmin = $apenasAdmin;
    }

    public static function superAdminOnly(): self
    {
        return new self(true);
    }

    public static function tenantOnly(): self
    {
        return new self(false);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $isAdminPanel = \App\Config\TenantResolver::isAdminPanel();

        if ($this->apenasAdmin && !$isAdminPanel) {
            $r = new Response(403);
            $r->getBody()->write(json_encode(['erro' => true, 'mensagem' => 'Acesso não autorizado.']));
            return $r->withHeader('Content-Type', 'application/json');
        }

        if (!$this->apenasAdmin && $isAdminPanel) {
            $r = new Response(400);
            $r->getBody()->write(json_encode(['erro' => true, 'mensagem' => 'Use o subdomínio da empresa.']));
            return $r->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}

// ============================================================
// RateLimitMiddleware — Limita tentativas por IP
// Implementação simples via ficheiros (compatível com shared hosting)
// ============================================================

class RateLimitMiddleware implements MiddlewareInterface
{
    private int $maxRequests;
    private int $windowSeconds;

    public function __construct(int $maxRequests = 5, int $windowSeconds = 60)
    {
        $this->maxRequests   = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'ratelimit_' . md5($ip . $request->getUri()->getPath());
        $dir = sys_get_temp_dir();
        $file = $dir . '/' . $key;

        $now   = time();
        $data  = file_exists($file) ? json_decode(file_get_contents($file), true) : ['count' => 0, 'window_start' => $now];

        if ($now - $data['window_start'] > $this->windowSeconds) {
            $data = ['count' => 0, 'window_start' => $now];
        }

        $data['count']++;
        file_put_contents($file, json_encode($data), LOCK_EX);

        if ($data['count'] > $this->maxRequests) {
            $retryAfter = $this->windowSeconds - ($now - $data['window_start']);
            $r = new Response(429);
            $r->getBody()->write(json_encode([
                'erro'     => true,
                'codigo'   => 'MUITAS_TENTATIVAS',
                'mensagem' => 'Demasiadas tentativas. Aguarde ' . $retryAfter . ' segundos.',
            ]));
            return $r
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string) $retryAfter)
                ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        return $handler->handle($request)
            ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $this->maxRequests - $data['count']));
    }
}

// ============================================================
// ZkBridgeAuthMiddleware — Autenticação por API Key para relógios
// ============================================================

class ZkBridgeAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $apiKey = $request->getHeaderLine('X-Bridge-Key');

        if (empty($apiKey)) {
            $r = new Response(401);
            $r->getBody()->write(json_encode(['erro' => true, 'mensagem' => 'API Key em falta.']));
            return $r->withHeader('Content-Type', 'application/json');
        }

        // Verificar se a API key corresponde a algum relógio activo do tenant
        // (A DB do tenant já foi resolvida pelo router)
        // A validação completa é feita no ZkBridgeController

        return $handler->handle($request->withAttribute('bridge_key', $apiKey));
    }
}
