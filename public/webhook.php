<?php
// Carrega .env (parser inline para evitar dependências)
$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    http_response_code(500);
    exit('Server misconfigured: .env missing');
}
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = array_map('trim', explode('=', $line, 2));
    $_ENV[$key] = $value;
}

$secret = $_ENV['WEBHOOK_SECRET'] ?? '';
if (empty($secret)) {
    http_response_code(500);
    exit('Server misconfigured: WEBHOOK_SECRET missing');
}

$payload = file_get_contents('php://input');
$signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($signature, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

$data = json_decode($payload, true);
if (($data['ref'] ?? '') === 'refs/heads/main') {
    exec('/var/www/saas/deploy.sh > /var/www/saas/logs/deploy.log 2>&1 &');
    echo 'Deploy iniciado';
} else {
    echo 'Branch ignorado';
}
