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
        $response = new Response();

        $authHeader = $request->getHeaderLine('Authorization');

        if (!str_starts_with($authHeader, 'Basic ')) {
            return $response->withStatus(401);
        }

        $credentials = base64_decode(substr($authHeader, 6));
        $parts = explode(':', $credentials, 2);
        if (count($parts) !== 2) {
            return $response->withStatus(401);
        }

        [$user, $pass] = $parts;

        $expectedUser = $_ENV['DAHUA_WEBHOOK_USER'] ?? '';
        $expectedPass = $_ENV['DAHUA_WEBHOOK_PASSWORD'] ?? '';

        if (empty($expectedUser) || empty($expectedPass)) {
            return $response->withStatus(401);
        }

        if (!hash_equals($expectedUser, $user) || !hash_equals($expectedPass, $pass)) {
            return $response->withStatus(401);
        }

        return $handler->handle($request);
    }
}
