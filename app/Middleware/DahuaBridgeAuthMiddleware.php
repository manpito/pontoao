<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class DahuaBridgeAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!str_starts_with($authHeader, 'Basic ')) {
            return $this->naoAutorizado();
        }

        $credentials = base64_decode(substr($authHeader, 6), true);
        if ($credentials === false) {
            return $this->naoAutorizado();
        }

        $parts = explode(':', $credentials, 2);
        if (count($parts) !== 2) {
            return $this->naoAutorizado();
        }

        [$user, $pass] = $parts;

        $expectedUser = $_ENV['DAHUA_WEBHOOK_USER'] ?? '';
        $expectedPass = $_ENV['DAHUA_WEBHOOK_PASSWORD'] ?? '';

        if (empty($expectedUser) || empty($expectedPass)) {
            return $this->naoAutorizado();
        }

        if (!hash_equals($expectedUser, $user) || !hash_equals($expectedPass, $pass)) {
            return $this->naoAutorizado();
        }

        return $handler->handle($request);
    }

    /**
     * 401 com o desafio WWW-Authenticate (RFC 7235). Sem este cabeçalho, os relógios
     * Dahua não reenviam o pedido com as credenciais Basic.
     */
    private function naoAutorizado(): ResponseInterface
    {
        return (new Response())
            ->withStatus(401)
            ->withHeader('WWW-Authenticate', 'Basic realm="PontoAO", charset="UTF-8"');
    }
}