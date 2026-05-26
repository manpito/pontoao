<?php

declare(strict_types=1);

namespace App\Config;

use DI\ContainerBuilder;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Bootstrap da aplicação — Slim 4 + PHP-DI
 * Carrega .env, configura DI container, regista serviços
 */
class App
{
    public static function bootstrap(): \Slim\App
    {
        self::loadEnv();

        $builder = new ContainerBuilder();
        $builder->addDefinitions(self::containerDefinitions());

        $container = $builder->build();

        $app = \Slim\Factory\AppFactory::createFromContainer($container);

        // Middleware global
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();

        $errorMiddleware = $app->addErrorMiddleware(
            displayErrorDetails: $_ENV['APP_DEBUG'] === 'true',
            logErrors: true,
            logErrorDetails: true,
            logger: $container->get(LoggerInterface::class),
        );

        // Handler de erros JSON para API
        $errorMiddleware->getDefaultErrorHandler()->forceContentType('application/json');

        // Registar rotas
        require __DIR__ . '/routes.php';

        return $app;
    }

    private static function containerDefinitions(): array
    {
        return [
            LoggerInterface::class => function () {
                $logger = new Logger('assiduidade');
                $logger->pushHandler(new RotatingFileHandler(
                    filename: $_ENV['LOG_PATH'] ?? __DIR__ . '/../../logs/app.log',
                    maxFiles: (int) ($_ENV['LOG_MAX_FILES'] ?? 30),
                    level: Level::fromName($_ENV['LOG_LEVEL'] ?? 'warning'),
                ));
                return $logger;
            },

            // Tenant context: resolvido no middleware de cada request
            'tenant.subdominio' => function () {
                return TenantResolver::resolve();
            },

            'db.master' => function () {
                return Database::master();
            },

            'db.tenant' => function (ContainerInterface $c) {
                $sub = $c->get('tenant.subdominio');
                return $sub ? Database::tenant($sub) : null;
            },
        ];
    }

    private static function loadEnv(): void
    {
        $envFile = __DIR__ . '/../../.env';

        if (!file_exists($envFile)) {
            throw new \RuntimeException('.env não encontrado. Copie .env.example para .env e configure.');
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}
