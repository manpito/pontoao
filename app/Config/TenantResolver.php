<?php

declare(strict_types=1);

namespace App\Config;

class TenantResolver
{
    public static function resolve(): ?string
    {
        // 1. Header X-Tenant (testes via curl / API calls do ponto.html)
        if (!empty($_SERVER['HTTP_X_TENANT'])) {
            return self::sanitize($_SERVER['HTTP_X_TENANT']);
        }

        // 2. Query string ?tenant= (ponto.html em ambiente local)
        if (!empty($_GET['tenant'])) {
            return self::sanitize($_GET['tenant']);
        }

        // 3. Subdomínio (produção: empresa.tuaplataforma.ao)
        $host    = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
        $appUrl  = $_ENV['APP_URL'] ?? '';
        $appHost = parse_url($appUrl, PHP_URL_HOST) ?? '';

        if ($host === $appHost || $host === 'admin.' . $appHost || $host === 'localhost' || $host === '127.0.0.1') {
            return null;
        }

        if (str_ends_with($host, '.' . $appHost)) {
            $sub = str_replace('.' . $appHost, '', $host);
            return $sub !== 'admin' ? self::sanitize($sub) : null;
        }

        return null;
    }

	public static function isAdminPanel(): bool
{
    $host   = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
    $appUrl = $_ENV['APP_URL'] ?? '';
    $appHost = parse_url($appUrl, PHP_URL_HOST) ?? '';

    return $host === 'admin.' . $appHost;
}

    private static function sanitize(string $sub): string
    {
        return preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($sub)));
    }
}
