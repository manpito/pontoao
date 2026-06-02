<?php
/**
 * Script de Teste Manual para Deteccao de Marcacoes em Falta
 * Uso: php scripts/testar_deteccao.php --tenant=ftl --data=2026-06-01 --dry-run
 */

declare(strict_types=1);

// Apenas repassa para o script principal com os argumentos fornecidos.
// O script principal já suporta --tenant, --data e --dry-run.

$args = array_slice($argv, 1);
$cmd = "php " . __DIR__ . "/detectar_marcacoes_em_falta.php " . implode(" ", array_map('escapeshellarg', $args));

passthru($cmd);
