<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

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

        return $handler->handle($request->withAttribute('bridge_key', $apiKey));
    }
}
