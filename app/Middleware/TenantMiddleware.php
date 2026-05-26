<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

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
