#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config\App;
use App\Config\Database;
use App\Services\FeriadoService;

App::bootstrap();

$opts = getopt('', ['ano:', 'tenant:']);
$ano = isset($opts['ano']) ? (int) $opts['ano'] : (int) date('Y');
$tenantSlug = $opts['tenant'] ?? null;

if (!$tenantSlug) {
    fwrite(STDERR, "Erro: --tenant=<slug> é obrigatório\n");
    fwrite(STDERR, "Uso: php bin/precarregar-feriados-moveis.php --ano=2026 --tenant=ftl\n");
    exit(1);
}

// Obter PDO do tenant via Database
$tenantPdo = Database::tenant($tenantSlug);
$service = new FeriadoService($tenantPdo);
$inseridos = $service->preCarregarFeriadosMoveis($ano);

echo "Tenant '{$tenantSlug}', ano {$ano}: {$inseridos} feriados móveis inseridos.\n";
exit(0);
