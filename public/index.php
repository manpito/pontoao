<?php

declare(strict_types=1);

/**
 * Ponto de entrada da aplicação
 * Todos os requests chegam aqui via .htaccess
 *
 * cPanel shared hosting compatible
 */

// Configurações de segurança antes de qualquer output
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:;");

// Autoloader Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Namespace imports
use App\Config\App;

// Iniciar aplicação e processar request
try {
    $app = App::bootstrap();
    $app->run();
}   catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'erro'     => true,
        'mensagem' => $e->getMessage(),
        'ficheiro' => $e->getFile() . ':' . $e->getLine(),
        'trace'    => $e->getTraceAsString(),
    ], JSON_UNESCAPED_UNICODE);
}