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
use App\Services\TerminalProcessamentoService;

class ZkBridgeController
{
    private const LOG = '/var/www/saas/logs/zk_debug.log';
    private TerminalProcessamentoService $terminalService;

    public function __construct()
    {
        $this->terminalService = new TerminalProcessamentoService();
    }

    private function log(string $msg): void
    {
        file_put_contents(self::LOG, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
    }

    private function registarAvisoSnDesconhecido(string $sn, string $payload): void
    {
        try {
            $masterDsn = "mysql:host=" . ($_ENV['DB_MASTER_HOST'] ?? 'localhost') .
                         ";dbname=" . ($_ENV['DB_MASTER_DATABASE'] ?? '') . ";charset=utf8mb4";
            $master = new PDO($masterDsn, $_ENV['DB_MASTER_USERNAME'], $_ENV['DB_MASTER_PASSWORD']);
            $master->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $master->prepare("
                UPDATE adms_avisos_globais
                SET criado_em = NOW(), payload_bruto = :payload
                WHERE sn_relogio = :sn AND tipo = 'sn_desconhecido' AND resolvido = 0
            ");
            $stmt->execute([':sn' => $sn, ':payload' => $payload]);

            if ($stmt->rowCount() === 0) {
                $master->prepare("
                    INSERT INTO adms_avisos_globais (tipo, sn_relogio, payload_bruto)
                    VALUES ('sn_desconhecido', :sn, :payload)
                ")->execute([
                    ':sn' => $sn,
                    ':payload' => $payload
                ]);
            }
        } catch (\Throwable $e) {
            $this->log("ERRO registarAvisoSnDesconhecido: " . $e->getMessage());
        }
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
                if ($this->terminalService->processarRegisto($db, $relogio, $registo)) {
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
        [$db, $relogio] = $this->terminalService->resolverTenantPorSN($sn);

        $raw = (string) $request->getBody();

        if (!$db || !$relogio) {
            $this->log("AVISO: SN={$sn} desconhecido. A registar em adms_avisos.");
            $this->registarAvisoSnDesconhecido($sn, $raw);
            $response->getBody()->write("OK: 0");
            return $response->withStatus(200)->withHeader('Content-Type', 'text/plain');
        }

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
                if ($this->terminalService->processarRegisto($db, $relogio, $registo)) {
                    $processados++;
                }
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $this->log("ERRO uid={$registo['UserID']}: " . $msg);

                if (str_contains($msg, "Funcionário '") && str_contains($msg, "' não encontrado.")) {
                    try {
                        $stmt = $db->prepare("
                            UPDATE adms_avisos
                            SET criado_em = NOW(), payload_bruto = :payload
                            WHERE tipo = 'funcionario_desconhecido' AND sn_relogio = :sn AND numero_funcionario = :func AND resolvido = 0
                        ");
                        $stmt->execute([
                            ':sn' => $sn,
                            ':func' => $registo['UserID'],
                            ':payload' => $linha
                        ]);

                        if ($stmt->rowCount() === 0) {
                            $db->prepare("
                                INSERT INTO adms_avisos (tipo, sn_relogio, numero_funcionario, payload_bruto)
                                VALUES ('funcionario_desconhecido', :sn, :func, :payload)
                            ")->execute([
                                ':sn' => $sn,
                                ':func' => $registo['UserID'],
                                ':payload' => $linha
                            ]);
                        }
                    } catch (\Throwable $e2) {
                        $this->log("ERRO ao inserir adms_avisos para uid={$registo['UserID']}: " . $e2->getMessage());
                    }
                }
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

        [$db, $relogio] = $this->terminalService->resolverTenantPorSN($sn);

        if (!$db || !$relogio) {
            $this->log("AVISO GETREQUEST: SN={$sn} desconhecido. A registar em adms_avisos.");
            $this->registarAvisoSnDesconhecido($sn, "GETREQUEST");
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

        [$db, $relogio] = $this->terminalService->resolverTenantPorSN($sn);

        if (!$db || !$relogio) {
            $this->log("AVISO DEVICECMD: SN={$sn} desconhecido. A registar em adms_avisos.");
            $this->registarAvisoSnDesconhecido($sn, "DEVICECMD raw={$raw}");
        }

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

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {

        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
