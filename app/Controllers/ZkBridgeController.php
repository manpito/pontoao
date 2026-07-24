<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * ZkBridgeController — Integração com relógios ZKTeco via protocolo Push/ADMS
 *
 * Endpoints:
 * POST /api/zk-bridge/marcacoes  — Recebe marcação do relógio
 * GET  /api/zk-bridge/ping       — Heartbeat / confirmação de ligação
 * GET  /api/zk-bridge/utilizadores — Relógio solicita lista de utilizadores
 * POST /api/zk-bridge/utilizadores — Relógio sincroniza utilizadores registados
 *
 * Protocolo ADMS ZKTeco:
 * O relógio envia POST com Content-Type: application/x-www-form-urlencoded
 * Campos principais: table, Timestamp, UserID, Verified, Status, WorkCode
 */
class ZkBridgeController
{
    private const LOG = '/var/www/saas/logs/zk_debug.log';

    private function log(string $msg): void
    {
        file_put_contents(self::LOG, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
    }

    // -------------------------------------------------------------------------
    // GET /api/zk-bridge/ping
    // -------------------------------------------------------------------------

    public function ping(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $relogio = $request->getAttribute('relogio');
        $db      = $request->getAttribute('tenant_db');

        if ($db && $relogio) {
            $db->prepare("UPDATE relogios SET ultimo_sync = NOW(), ultimo_heartbeat = NOW() WHERE id = :id")
               ->execute([':id' => $relogio['id']]);
        }

        $response->getBody()->write("OK");
        return $response->withStatus(200)->withHeader('Content-Type', 'text/plain');
    }

    // -------------------------------------------------------------------------
    // POST /api/zk-bridge/marcacoes  (formato JSON ou form-urlencoded)
    // -------------------------------------------------------------------------

    public function receive(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $relogio     = $request->getAttribute('relogio');
        $db          = $request->getAttribute('tenant_db');
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $body = $request->getParsedBody() ?? [];
        } else {
            $raw = (string) $request->getBody();
            parse_str($raw, $body);
            if (empty($body)) {
                $body = $request->getParsedBody() ?? [];
            }
        }

        // Normalizar para array de registos
        if (isset($body['table']) && $body['table'] === 'ATTLOG') {
            $registos = [$body];
        } elseif (isset($body[0])) {
            $registos = $body;
        } elseif (isset($body['registos'])) {
            $registos = $body['registos'];
        } else {
            $registos = [$body];
        }

        $processados = 0;
        $erros       = [];

        foreach ($registos as $registo) {
            try {
                if ($this->processarRegisto($db, $relogio, $registo)) {
                    $processados++;
                }
            } catch (\Throwable $e) {
                $erros[] = $e->getMessage();
                $this->log("ERRO receive: " . $e->getMessage());
            }
        }

        $response->getBody()->write(json_encode([
            'processados' => $processados,
            'erros'       => count($erros),
            'mensagem'    => "OK: {$processados} marcação(ões) registada(s).",
        ]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    // -------------------------------------------------------------------------
    // GET /api/zk-bridge/utilizadores
    // -------------------------------------------------------------------------

    public function listarUtilizadores(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $request->getAttribute('tenant_db');

        $stmt = $db->query("
            SELECT
                f.numero_funcionario AS UserID,
                f.nome_completo      AS Name,
                f.pin_marcacao       AS Password,
                '0'                  AS Privilege,
                '1'                  AS Enabled
            FROM funcionarios f
            WHERE f.estado = 'activo'
            ORDER BY f.numero_funcionario ASC
        ");

        $utilizadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode([
            'utilizadores' => $utilizadores,
            'total'        => count($utilizadores),
        ]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    // -------------------------------------------------------------------------
    // POST /api/zk-bridge/relogios
    // -------------------------------------------------------------------------

    public function registarRelogio(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $db   = $request->getAttribute('tenant_db');

        if (empty($body['nome']) || empty($body['ip']) || empty($body['api_key'])) {
            return $this->json($response, 422, ['erro' => true, 'mensagem' => 'nome, ip e api_key são obrigatórios.']);
        }

        $apiKeyHash = hash('sha256', $body['api_key']);

        $existing = $db->prepare("SELECT id FROM relogios WHERE ip = :ip LIMIT 1");
        $existing->execute([':ip' => $body['ip']]);
        $existe = $existing->fetch(PDO::FETCH_ASSOC);

        if ($existe) {
            $db->prepare("
                UPDATE relogios
                SET nome = :nome, localizacao = :loc, modelo = :modelo,
                    api_key_hash = :hash, activo = 1
                WHERE id = :id
            ")->execute([
                ':nome'   => $body['nome'],
                ':loc'    => $body['localizacao'] ?? null,
                ':modelo' => $body['modelo'] ?? 'zkteco',
                ':hash'   => $apiKeyHash,
                ':id'     => $existe['id'],
            ]);
            $id  = $existe['id'];
            $msg = 'Relógio actualizado.';
        } else {
            $db->prepare("
                INSERT INTO relogios (nome, localizacao, ip, porta, modelo, api_key_hash, activo)
                VALUES (:nome, :loc, :ip, :porta, :modelo, :hash, 1)
            ")->execute([
                ':nome'   => $body['nome'],
                ':loc'    => $body['localizacao'] ?? null,
                ':ip'     => $body['ip'],
                ':porta'  => (int) ($body['porta'] ?? 4370),
                ':modelo' => $body['modelo'] ?? 'zkteco',
                ':hash'   => $apiKeyHash,
            ]);
            $id  = (int) $db->lastInsertId();
            $msg = 'Relógio registado com sucesso.';
        }

        return $this->json($response, 200, ['mensagem' => $msg, 'id' => $id]);
    }

    // -------------------------------------------------------------------------
    // GET /iclock/cdata — Handshake inicial ADMS
    // -------------------------------------------------------------------------

    public function admsCdata(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params  = $request->getQueryParams();
        $sn      = $params['SN'] ?? 'unknown';
        $db      = $request->getAttribute('tenant_db');
        $relogio = $request->getAttribute('relogio');

        if ($db && $relogio && empty($relogio['device_id'])) {
            $db->prepare("UPDATE relogios SET device_id = :sn, ultimo_heartbeat = NOW() WHERE id = :id")
               ->execute([':sn' => $sn, ':id' => $relogio['id']]);
        }

        $attlogStamp = "None";
        if ($sn === '5450251100118') {
            $attlogStamp = "20260701000000";
        }

        $body = implode("\n", [
            "GET OPTION FROM: {$sn}",
            "ATTLOGStamp={$attlogStamp}",
            "OPERLOGStamp=9999",
            "ATTPHOTOStamp=None",
            "ErrorDelay=30",
            "Delay=10",
            "TransTimes=00:00;14:05",
            "TransInterval=1",
            "TransFlag=TransData AttLog",
            "Realtime=1",
            "Encrypt=None",
            "ServerVer=2.4.1",
            "PushProtVer=2.4.1",
            "PushOptionsFlag=1",
            "TimeZone=1",
            "SyncTime=0",
        ]);

        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('X-ZKTeco-Ver', '2.4.1');
    }

    // -------------------------------------------------------------------------
    // POST /iclock/cdata — Relógio envia marcações (ATTLOG)
    // -------------------------------------------------------------------------

    public function admsPostCdata(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params  = $request->getQueryParams();
        $table   = $params['table'] ?? '';
        $sn      = $params['SN'] ?? '';

        // Resolver DB e relógio pelo SN
        [$db, $relogio] = $this->resolverTenantPorSN($sn);

        $raw = (string) $request->getBody();

        if ($table !== 'ATTLOG' || empty($raw)) {
            $response->getBody()->write("OK");
            return $response->withStatus(200)->withHeader('Content-Type', 'text/plain');
        }

        $processados = 0;
        $linhas      = array_filter(explode("\n", str_replace("\r\n", "\n", $raw)));

        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if (empty($linha)) {
                continue;
            }

            $partes = preg_split('/\s+/', $linha);
            $count  = count($partes);

            if ($count < 3) {
                continue;
            }

            $registo = [
                'UserID'    => $partes[0],
                'Timestamp' => $partes[1] . ' ' . $partes[2],
                'Status'    => $partes[3] ?? 0,
                'Verified'  => $partes[4] ?? 1,
            ];

            try {
                if ($this->processarRegisto($db, $relogio, $registo)) {
                    $processados++;
                }
            } catch (\Throwable $e) {
                $this->log("ERRO uid={$registo['UserID']}: " . $e->getMessage());
            }
        }

        if ($db && $relogio) {
            $db->prepare("UPDATE relogios SET ultimo_sync = NOW(), ultimo_heartbeat = NOW() WHERE id = :id")
               ->execute([':id' => $relogio['id']]);
        }

        $response->getBody()->write("OK: {$processados}");
        return $response->withStatus(200)->withHeader('Content-Type', 'text/plain');
    }

    // -------------------------------------------------------------------------
    // GET /iclock/getrequest
    // -------------------------------------------------------------------------

    public function admsGetRequest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $sn     = $params['SN'] ?? '';

        [$db, $relogio] = $this->resolverTenantPorSN($sn);

        if (!$db || !$relogio) {
            $response->getBody()->write("OK");
            return $response->withStatus(200)->withHeader('Content-Type', 'text/plain');
        }

        $zkService = new \App\Services\ZkComandoService($db);
        $comando   = $zkService->obterProximoComando((int) $relogio['id']);

        if (!$comando) {
            $response->getBody()->write("OK");
            return $response->withStatus(200)->withHeader('Content-Type', 'text/plain');
        }

        $zkService->marcarEnviado((int) $comando['id']);

        $body = "C:{$comando['id']}:{$comando['payload']}";
        $this->log("GETREQUEST SN={$sn} -> CMD {$comando['id']}: {$comando['payload']}");

        $response->getBody()->write($body);
        return $response->withStatus(200)->withHeader('Content-Type', 'text/plain');
    }

    // -------------------------------------------------------------------------
    // POST /iclock/devicecmd
    // -------------------------------------------------------------------------

    public function admsDeviceCmd(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $sn     = $params['SN'] ?? '';
        $raw    = (string) $request->getBody();

        $this->log("DEVICECMD SN={$sn} raw={$raw}");

        [$db, $relogio] = $this->resolverTenantPorSN($sn);

        if ($db && $relogio) {
            // Formato de confirmação do relógio: "ID=1\nReturn=0\nCMD=DATA UPDATE USERINFO"
            if (preg_match('/ID=(\d+)/', $raw, $m)) {
                $cmdId = (int) $m[1];
                $sucesso = str_contains($raw, 'Return=0');
                $zkService = new \App\Services\ZkComandoService($db);
                if ($sucesso) {
                    $zkService->marcarConfirmado($cmdId);
                    $this->log("CMD {$cmdId} confirmado");
                } else {
                    $zkService->marcarErro($cmdId, $raw);
                    $this->log("CMD {$cmdId} erro: {$raw}");
                }
            }
        }

        $response->getBody()->write("OK");
        return $response->withStatus(200)->withHeader('Content-Type', 'text/plain');
    }

    // -------------------------------------------------------------------------
    // Lógica interna
    // -------------------------------------------------------------------------

    private function processarRegisto(PDO $db, array $relogio, array $registo): bool
    {
        $userId    = trim($registo['UserID']    ?? $registo['user_id']    ?? '');
        $timestamp = trim($registo['Timestamp'] ?? $registo['timestamp']  ?? $registo['data_hora'] ?? '');
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

        $tipo   = $this->mapearTipo($status);
        $origem = $this->mapearOrigem($verified);

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
            $this->log("DUPLICADO uid={$userId} ts={$dataHora}");
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

    private function resolverTenantPorSN(string $sn): array
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

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {

        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
