<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\App;
use App\Config\Database;
use PDO;

App::bootstrap();

$master = Database::master();
$stmt = $master->query("SELECT id, subdominio FROM tenants WHERE estado != 'cancelado'");
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$migrationFile = __DIR__ . '/../database/migrations/tenant/013_tipos_justificacao.sql';
$sql = file_get_contents($migrationFile);

foreach ($tenants as $t) {
    try {
        echo "Migrando tenant {$t['subdominio']}... ";
        $db = Database::tenant($t['subdominio']);

        $db->exec($sql);

        $master->prepare("INSERT IGNORE INTO tenant_migrations (tenant_id, migration) VALUES (?, '013_tipos_justificacao.sql')")
               ->execute([$t['id']]);

        echo "OK\n";
    } catch (\Exception $e) {
        if (!str_contains($e->getMessage(), 'already exists')) {
            echo "Erro: " . $e->getMessage() . "\n";
        } else {
            echo "Ja existe.\n";
        }
    }
}
