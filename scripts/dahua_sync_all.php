<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Services\DahuaISAPIService;

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        // Remover aspas envolventes se existirem
        if (strlen($value) >= 2 && (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        )) {
            $value = substr($value, 1, -1);
        }
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

$tenantId = $argv[1] ?? null;

if (!$tenantId || $tenantId === 'default') {
    echo "Tenant ID não fornecido ou inválido.\n";
    exit(1);
}

$logFile = '/var/www/saas/logs/dahua_sync.log';
$logSync = function(string $message) use ($logFile) {
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
};

try {
    $db = Database::tenant($tenantId);
    $dahuaService = new DahuaISAPIService();
    if (empty($_ENV['APP_KEY'])) {
        $logSync("CRITICAL: APP_KEY não configurada no .env. Abortando.");
        exit(1);
    }
    $appKey = $_ENV['APP_KEY'];

    $stmtRelogios = $db->query("SELECT * FROM relogios WHERE tipo_protocolo = 'dahua' AND activo = 1");
    $relogios = $stmtRelogios->fetchAll(PDO::FETCH_ASSOC);

    if (empty($relogios)) {
        $logSync("INFO: Nenhum relógio Dahua activo encontrado para o tenant $tenantId.");
        exit(0);
    }

    $stmtFuncionarios = $db->query("SELECT numero_funcionario, nome_completo AS nome FROM funcionarios WHERE estado = 'activo'");
    $funcionarios = $stmtFuncionarios->fetchAll(PDO::FETCH_ASSOC);

    if (empty($funcionarios)) {
        $logSync("INFO: Nenhum funcionário activo encontrado para o tenant $tenantId.");
        exit(0);
    }

    $logSync("INFO: Iniciando sincronização em lote para " . count($funcionarios) . " funcionários em " . count($relogios) . " relógio(s).");

    foreach ($relogios as $relogio) {
        $deviceUrl = $relogio['device_url'];
        $deviceUser = $relogio['device_user'];
        $devicePasswordEnc = $relogio['device_password_enc'];

        if (!$deviceUrl || !$deviceUser || !$devicePasswordEnc) {
            $logSync("WARNING: Relógio {$relogio['nome']} (ID: {$relogio['id']}) não tem as configurações Dahua completas. Ignorando.");
            continue;
        }

        $password = openssl_decrypt($devicePasswordEnc, 'aes-256-cbc', $appKey, 0, substr($appKey, 0, 16));

        $successCount = 0;
        $errorCount = 0;

        foreach ($funcionarios as $funcionario) {
            try {
                $sucesso = $dahuaService->sincronizarFuncionario($deviceUrl, $deviceUser, $password, $funcionario);
                if ($sucesso) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            } catch (Exception $e) {
                $logSync("ERROR: Sincronização falhou para {$funcionario['numero_funcionario']} no relógio {$relogio['id']}: " . $e->getMessage());
                $errorCount++;
            }
        }

        $logSync("INFO: Sincronização concluída para o relógio {$relogio['nome']}. Sucesso: $successCount, Erros: $errorCount.");
    }

    $logSync("INFO: Processo de sincronização em lote finalizado para o tenant $tenantId.");

} catch (Exception $e) {
    $logSync("CRITICAL: Erro na sincronização em lote: " . $e->getMessage());
    exit(1);
}
