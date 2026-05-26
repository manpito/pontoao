<?php

declare(strict_types=1);

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use UnexpectedValueException;

/**
 * Serviço de autenticação JWT
 * Access token: 1 hora | Refresh token: 30 dias
 */
class AuthService
{
    private string $secret;
    private string $algo;
    private int $accessTtl;
    private int $refreshTtl;

    public function __construct()
    {
        $this->secret     = $_ENV['JWT_SECRET'];
        $this->algo       = $_ENV['JWT_ALGO'] ?? 'HS256';
        $this->accessTtl  = (int) ($_ENV['JWT_ACCESS_TTL'] ?? 3600);
        $this->refreshTtl = (int) ($_ENV['JWT_REFRESH_TTL'] ?? 2592000);
    }

    /**
     * Gera access token JWT com claims do utilizador
     */
    public function generateAccessToken(array $user, string $tenantSub = ''): string
    {
        $now = time();

        $payload = [
            'iss'            => $_ENV['APP_URL'],
            'iat'            => $now,
            'exp'            => $now + $this->accessTtl,
            'sub'            => (string) $user['id'],
            'uuid'           => $user['uuid'],
            'nome'           => $user['nome'],
            'email'          => $user['email'],
            'perfil'         => $user['perfil'],
            'tenant'         => $tenantSub,
            'funcionario_id' => isset($user['funcionario_id']) ? (int) $user['funcionario_id'] : null,
        ];

        return JWT::encode($payload, $this->secret, $this->algo);
    }

    /**
     * Gera refresh token opaco (random) — armazenado na DB como hash
     */
    public function generateRefreshToken(): string
    {
        return bin2hex(random_bytes(48));
    }

    /**
     * Valida e decodifica access token
     */
    public function validateAccessToken(string $token): object
    {
        try {
            return JWT::decode($token, new Key($this->secret, $this->algo));
        } catch (UnexpectedValueException $e) {
            throw new RuntimeException('Token inválido ou expirado.', 401);
        }
    }

    /**
     * Hash do refresh token para armazenamento seguro
     */
    public function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Extrai Bearer token do header Authorization
     */
    public function extractBearerToken(string $authHeader): ?string
    {
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function getRefreshTtl(): int
    {
        return $this->refreshTtl;
    }
}
