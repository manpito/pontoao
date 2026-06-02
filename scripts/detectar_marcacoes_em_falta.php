<?php
/**
 * Script de Detecção Automática de Marcações em Falta
 * Corre diariamente à meia-noite via Cron.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Config\Database;
use App\Services\EscalaService;
use App\Services\FeriadoService;

// 1. Carregar .env (parser inline)
$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    die("Erro: .env não encontrado.\n");
}
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $value] = array_map('trim', explode('=', $line, 2));
    $_ENV[$key] = $value;
}

// 2. Parse CLI arguments
$options = getopt('', ['tenant:', 'data:', 'dry-run']);
$targetTenant = $options['tenant'] ?? null;
$targetDate = $options['data'] ?? date('Y-m-d', strtotime('-1 day'));
$dryRun = isset($options['dry-run']);

if ($dryRun) {
    echo "[DRY-RUN] Modo de simulação activo. Nenhuma alteração será gravada na DB.\n";
}

try {
    $masterPdo = Database::master();
} catch (Exception $e) {
    die("Erro ao ligar à DB master: " . $e->getMessage() . "\n");
}

// 3. Obter lista de tenants activos
$query = "SELECT id, subdominio as slug, nome_empresa as nome FROM tenants WHERE estado = 'activo'";
if ($targetTenant) {
    $query .= " AND subdominio = :slug";
}
$stmtTenants = $masterPdo->prepare($query);
if ($targetTenant) {
    $stmtTenants->execute(['slug' => $targetTenant]);
} else {
    $stmtTenants->execute();
}

$tenants = $stmtTenants->fetchAll(PDO::FETCH_ASSOC);

echo "Processando " . count($tenants) . " tenant(s) para a data {$targetDate}...\n";

$totalFaltasGlobal = 0;
$tenantsProcessados = 0;

foreach ($tenants as $tenant) {
    $tenantsProcessados++;
    $slug = $tenant['slug'];
    $faltasNoTenant = 0;
    echo "[$slug] Processando: {$tenant['nome']}...\n";

    try {
        $pdo = Database::tenant($slug);

        $feriadoService = new FeriadoService($pdo);
        $escalaService = new EscalaService($pdo);

        // c. Verificar se ontem era feriado
        if ($feriadoService->isFeriado($targetDate)) {
            echo "[$slug] {$targetDate} é feriado. Saltando.\n";
            continue;
        }

        // e. Para cada funcionário activo do tenant
        $stmtFunc = $pdo->prepare("SELECT id, nome_completo FROM funcionarios WHERE estado = 'activo'");
        $stmtFunc->execute();
        $funcionarios = $stmtFunc->fetchAll(PDO::FETCH_ASSOC);

        foreach ($funcionarios as $func) {
            $funcId = (int)$func['id'];
            $funcNome = $func['nome_completo'];

            // i. Verificar se tem escala activa
            $turno = $escalaService->calcularTurnoEm($funcId, $targetDate);
            if (!$turno || $turno['tipo'] === 'folga') {
                continue;
            }

            // iii. Verificar se existe excepção em escala_excepcoes (ex: substituição já tratada)
            // Nota: EscalaService::calcularTurnoEm já verifica se há substituto/ausente.
            // Se o funcionário estiver ausente com substituto, o tipo retornado é 'folga'.
            // Mas o requisito pede para verificar explicitamente se existe excepção.
            $stmtExc = $pdo->prepare("SELECT id FROM escala_excepcoes WHERE funcionario_ausente_id = :fid AND data = :data LIMIT 1");
            $stmtExc->execute(['fid' => $funcId, 'data' => $targetDate]);
            if ($stmtExc->fetch()) {
                continue;
            }

            // iv. Verificar se existe férias aprovada
            $stmtFerias = $pdo->prepare("
                SELECT id FROM ferias_pedidos
                WHERE funcionario_id = :fid
                AND :data BETWEEN data_inicio AND data_fim
                AND estado IN ('aprovado_supervisor', 'aprovado_rh')
                LIMIT 1
            ");
            $stmtFerias->execute(['fid' => $funcId, 'data' => $targetDate]);
            if ($stmtFerias->fetch()) {
                continue;
            }

            // v. Verificar se existe marcação de ENTRADA
            $stmtMarc = $pdo->prepare("
                SELECT id, tipo FROM marcacoes
                WHERE funcionario_id = :fid
                AND DATE(data_hora) = :data
                ORDER BY data_hora ASC
            ");
            $stmtMarc->execute(['fid' => $funcId, 'data' => $targetDate]);
            $marcacoes = $stmtMarc->fetchAll(PDO::FETCH_ASSOC);

            $temEntrada = false;
            $temSaida = false;
            $entradaId = null;

            foreach ($marcacoes as $m) {
                if ($m['tipo'] === 'entrada') {
                    $temEntrada = true;
                    $entradaId = $m['id'];
                } elseif ($m['tipo'] === 'saida') {
                    $temSaida = true;
                }
            }

            $detectouFalta = false;
            $nota = "";
            $tipoFalta = ""; // Para o output

            if (!$temEntrada) {
                $detectouFalta = true;
                $tipoFalta = "falta_entrada";
                $nota = "Falta de entrada";
            } elseif (!$temSaida) {
                $detectouFalta = true;
                $tipoFalta = "falta_saida";
                $nota = "Falta de saída";
            }

            if ($detectouFalta) {
                $faltasNoTenant++;
                if ($dryRun) {
                    echo "[DRY-RUN] Funcionário $funcId ($funcNome): $tipoFalta detectada ($nota)\n";
                } else {
                    // vii. Usar INSERT IGNORE para idempotência
                    $stmtIns = $pdo->prepare("
                        INSERT IGNORE INTO marcacoes_em_falta (funcionario_id, data, marcacao_entrada_id, estado, nota_classificacao)
                        VALUES (:fid, :data, :meid, 'pendente', :nota)
                    ");
                    $stmtIns->execute([
                        'fid' => $funcId,
                        'data' => $targetDate,
                        'meid' => $entradaId,
                        'nota' => $nota
                    ]);
                }
            }
        }

        echo "[$slug] Total: $faltasNoTenant falta(s) detectada(s).\n";
        $totalFaltasGlobal += $faltasNoTenant;

    } catch (Exception $e) {
        error_log("[$slug] Erro ao processar tenant: " . $e->getMessage());
        echo "[$slug] ERRO: " . $e->getMessage() . "\n";
        continue;
    }
}

echo "\nFim do processamento.\n";
echo "Tenants processados: $tenantsProcessados\n";
echo "Total de faltas detectadas: $totalFaltasGlobal\n";
