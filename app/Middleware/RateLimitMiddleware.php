<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

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
        $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key  = 'ratelimit_' . md5($ip . $request->getUri()->getPath());
        $dir  = sys_get_temp_dir();
        $file = $dir . '/' . $key;

        $now  = time();
        $data = file_exists($file)
            ? json_decode(file_get_contents($file), true)
            : ['count' => 0, 'window_start' => $now];

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
