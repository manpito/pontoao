<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Resolução do tenant a partir do subdomínio do request HTTP
 *
 * empresa1.tuaplataforma.ao → subdomínio = "empresa1"
 * admin.tuaplataforma.ao   → super-admin, sem tenant
 * tuaplataforma.ao         → landing/marketing
 */
class TenantResolver
{
    private static ?string $resolved = null;

    public static function resolve(): ?string
    {
        if (self::$resolved !== null) {
            return self::$resolved === '' ? null : self::$resolved;
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';
        $appDomain = parse_url($_ENV['APP_URL'] ?? '', PHP_URL_HOST) ?? '';

        // Remover porta se presente
        $host = strtolower(explode(':', $host)[0]);

        // Se for o domínio raiz ou admin, não é tenant
        if ($host === $appDomain || $host === 'admin.' . $appDomain) {
            self::$resolved = '';
            return null;
        }

        // Extrair subdomínio
        if (str_ends_with($host, '.' . $appDomain)) {
            $sub = substr($host, 0, strlen($host) - strlen('.' . $appDomain));

            // Validar formato do subdomínio (alfanumérico + hífen)
            if (preg_match('/^[a-z0-9\-]{2,60}$/', $sub) && $sub !== 'www') {
                self::$resolved = $sub;
                return $sub;
            }
        }

        self::$resolved = '';
        return null;
    }

    public static function isAdminPanel(): bool
    {
        $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
        $appDomain = parse_url($_ENV['APP_URL'] ?? '', PHP_URL_HOST) ?? '';
        return $host === 'admin.' . $appDomain;
    }
}
