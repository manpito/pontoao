<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class TerminalProcessamentoService
{
    private const LOG = '/var/www/saas/logs/zk_debug.log';

    private function log(string $msg): void
    {
        file_put_contents(self::LOG, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
    }

    public function processarRegisto(PDO $db, array $relogio, array $registo): bool
    {
        $userId    = trim((string) ($registo['UserID']    ?? $registo['user_id']    ?? ''));
        $timestamp = trim((string) ($registo['Timestamp'] ?? $registo['timestamp']  ?? $registo['data_hora'] ?? ''));
        $status    = (int) ($registo['Status']  ?? $registo['status']     ?? 0);
        $verified  = (int) ($registo['Verified'] ?? $registo['verified']  ?? 0);

        if (empty($userId) || empty($timestamp)) {
            $this->log("SKIP registo sem userId ou timestamp");
            return false;
        }

        $dataHora = $this->normalizarTimestamp($timestamp);
        if (!$dataHora) {
            $this->log("SKIP timestamp inválido: {$timestamp}");
            return false;
        }

        $tipo   = $registo['tipo_dahua'] ?? $this->mapearTipo($status);
        $origem = $registo['origem_dahua'] ?? $this->mapearOrigem($verified);

        // Encontrar funcionário
        $stmt = $db->prepare("
            SELECT id FROM funcionarios
            WHERE numero_funcionario = :uid AND estado = 'activo'
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $func = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$func) {
            // Tentar sem zeros à esquerda
            $stmt2 = $db->prepare("
                SELECT id FROM funcionarios
                WHERE CAST(numero_funcionario AS UNSIGNED) = :uid AND estado = 'activo'
                LIMIT 1
            ");
            $stmt2->execute([':uid' => (int) $userId]);
            $func = $stmt2->fetch(PDO::FETCH_ASSOC);
        }

        if (!$func) {
            throw new \RuntimeException("Funcionário '{$userId}' não encontrado.");
        }

        // Verificação de grupo de segurança (departamento)
        $stmtDeps = $db->prepare("SELECT departamento_id FROM relogio_departamentos WHERE relogio_id = :rid");
        $stmtDeps->execute([':rid' => $relogio['id']]);
        $depsPermitidos = $stmtDeps->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($depsPermitidos)) {
            // Se o relógio tem restrições, verificar se o funcionário pertence a um dos departamentos permitidos
            $stmtF = $db->prepare("SELECT departamento_id FROM funcionarios WHERE id = :fid");
            $stmtF->execute([':fid' => $func['id']]);
            $depFunc = $stmtF->fetchColumn();

            if (!in_array($depFunc, $depsPermitidos)) {
                $timestampLog = date('Y-m-d H:i:s');
                $logMsg = "[BLOQUEIO] {$timestampLog} Terminal:{$relogio['nome']} Funcionário:{$userId} Departamento:{$depFunc} não autorizado";
                $this->log($logMsg);
                return false;
            }
        }

        // Verificar duplicado (mesmo funcionário, mesmo minuto, mesmo tipo)
        $checkDup = $db->prepare("
            SELECT id FROM marcacoes
            WHERE funcionario_id = :fid
              AND ABS(TIMESTAMPDIFF(SECOND, data_hora, :dh)) < 60
              AND tipo = :tipo
            LIMIT 1
        ");
        $checkDup->execute([':fid' => $func['id'], ':dh' => $dataHora, ':tipo' => $tipo]);
        if ($checkDup->fetch()) {
            $sn = $relogio['device_id'] ?? 'unknown';
            $this->log("DUPLICADO sn={$sn} uid={$userId} ts={$dataHora}");
            return false;
        }

        // Verificar período mensal fechado
        [$ano, $mes] = explode('-', substr($dataHora, 0, 7));
        $periodo = $db->prepare("SELECT estado FROM periodos_mensais WHERE ano = :ano AND mes = :mes LIMIT 1");
        $periodo->execute([':ano' => $ano, ':mes' => (int) $mes]);
        $p = $periodo->fetch(PDO::FETCH_ASSOC);
        if ($p && $p['estado'] === 'fechado') {
            throw new \RuntimeException("Período mensal fechado — marcação rejeitada.");
        }

        // Inserir
        $db->prepare("
            INSERT INTO marcacoes (
                funcionario_id, tipo, data_hora, data_hora_original,
                origem, relogio_id, ip_marcacao
            ) VALUES (
                :fid, :tipo, :dh, :dh_original,
                :origem, :relogio_id, :ip
            )
        ")->execute([
            ':fid'         => $func['id'],
            ':tipo'        => $tipo,
            ':dh'          => $dataHora,
            ':dh_original' => $dataHora,
            ':origem'      => $origem,
            ':relogio_id'  => $relogio['id'],
            ':ip'          => $relogio['ip'] ?? null,
        ]);

        return true;
    }

    private function mapearTipo(int $status): string
    {
        return match ($status) {
            0       => 'entrada',
            1       => 'saida',
            2       => 'inicio_intervalo',
            3       => 'fim_intervalo',
            4       => 'saida_servico',
            5       => 'regresso_servico',
            default => 'entrada',
        };
    }

    private function mapearOrigem(int $verified): string
    {
        return match ($verified) {
            1       => 'relogio', // impressão digital
            4       => 'relogio', // reconhecimento facial
            15      => 'relogio', // cartão RFID
            200     => 'web_pin',
            default => 'relogio',
        };
    }

    private function normalizarTimestamp(string $ts): ?string
    {
        $ts = str_replace('/', '-', trim($ts));

        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $ts);
        if ($dt) return $dt->format('Y-m-d H:i:s');

        $dt = \DateTime::createFromFormat('Y-m-d\TH:i:s', $ts);
        if ($dt) return $dt->format('Y-m-d H:i:s');

        $dt = \DateTime::createFromFormat('Y-m-d H:i', $ts);
        if ($dt) return $dt->format('Y-m-d H:i:s');

        $time = strtotime($ts);
        return $time ? date('Y-m-d H:i:s', $time) : null;
    }

    public function resolverTenantPorSN(string $sn): array
    {
        $db      = null;
        $relogio = null;

        $masterDsn = "mysql:host=" . ($_ENV['DB_MASTER_HOST'] ?? 'localhost') .
                     ";dbname=" . ($_ENV['DB_MASTER_DATABASE'] ?? '') . ";charset=utf8mb4";
        try {
            $master  = new \PDO($masterDsn, $_ENV['DB_MASTER_USERNAME'], $_ENV['DB_MASTER_PASSWORD']);
            $stmt    = $master->prepare("SELECT db_nome, db_host FROM tenants LIMIT 100");
            $stmt->execute();
            $tenants = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($tenants as $tenant) {
                $dsn = "mysql:host=" . ($tenant['db_host'] ?? 'localhost') .
                       ";dbname=" . $tenant['db_nome'] . ";charset=utf8mb4";
                try {
                    $tdb   = new \PDO($dsn, $_ENV['DB_MASTER_USERNAME'], $_ENV['DB_MASTER_PASSWORD']);
                    $stmt2 = $tdb->prepare("SELECT * FROM relogios WHERE device_id = :sn LIMIT 1");
                    $stmt2->execute([':sn' => $sn]);
                    $rel   = $stmt2->fetch(\PDO::FETCH_ASSOC);
                    if ($rel) {
                        $db      = $tdb;
                        $relogio = $rel;
                        break;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        } catch (\Throwable $e) {
            $this->log("ERRO master DB: " . $e->getMessage());
        }

        // Fallback directo ao tenant se SN não encontrado
        if (!$db) {
            $this->log("AVISO: SN={$sn} não encontrado, usando fallback");
            $dbName = 'ftlangol_' . ($_ENV['DB_TENANT_PREFIX'] ?? 'tenant_') . '009_ftl';
            $dsn    = "mysql:host=localhost;dbname={$dbName};charset=utf8mb4";
            try {
                $db    = new \PDO($dsn, $_ENV['DB_MASTER_USERNAME'], $_ENV['DB_MASTER_PASSWORD']);
                $stmt  = $db->prepare("SELECT * FROM relogios WHERE device_id = :sn LIMIT 1");
                $stmt->execute([':sn' => $sn]);
                $relogio = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
                if (!$relogio) {
                    $stmt2   = $db->prepare("SELECT * FROM relogios LIMIT 1");
                    $stmt2->execute();
                    $relogio = $stmt2->fetch(\PDO::FETCH_ASSOC) ?: null;
                }
            } catch (\Throwable $e) {
                $this->log("ERRO fallback DB: " . $e->getMessage());
            }
        }

        return [$db, $relogio];
    }
}
