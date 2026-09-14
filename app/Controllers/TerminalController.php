<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * TerminalController — Gestão de terminais biométricos e grupos de segurança
 */
class TerminalController
{
    /**
     * GET /api/terminais
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->query("
            SELECT r.*, d.nome AS departamento_default_nome,
                   (SELECT MAX(timestamp) FROM agent_sync_log WHERE relogio_id = r.id AND sucesso = 1) AS ultima_sincronizacao
            FROM relogios r
            LEFT JOIN departamentos d ON r.default_departamento_id = d.id
            ORDER BY r.nome ASC
        ");
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Adicionar estado online/offline baseado no último heartbeat (2 horas)
        foreach ($dados as &$d) {
            $ultimo = $d['ultimo_heartbeat'] ? strtotime($d['ultimo_heartbeat']) : 0;
            $d['online'] = (time() - $ultimo) < 7200;
        }

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * GET /api/terminais/{id}/agent-download
     */
    public function downloadAgente(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->prepare("SELECT * FROM relogios WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $terminal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$terminal || $terminal['tipo_protocolo'] !== 'dahua') {
            return $response->withStatus(400);
        }

        $tenantId = $sub;
        $pontoaoUrl = 'https://rh.ftl-angola.net';

        // Gerar chave nova para esta instalação
        $rawKey  = bin2hex(random_bytes(16)); // 32 caracteres hex
        $keyHash = hash('sha256', $rawKey);

        // Actualizar AGENT_API_KEY_HASH no .env (substituir a linha existente)
        $envPath = __DIR__ . '/../../.env';
        $envContent = file_get_contents($envPath);
        if (preg_match('/^AGENT_API_KEY_HASH=.*/m', $envContent)) {
            $envContent = preg_replace('/^AGENT_API_KEY_HASH=.*/m', "AGENT_API_KEY_HASH=$keyHash", $envContent);
        } else {
            $envContent .= "\nAGENT_API_KEY_HASH=$keyHash\n";
        }
        file_put_contents($envPath, $envContent);

        // Gerar config.ini com a chave raw — este é o ÚNICO sítio onde o raw existe
        $configIni  = "[pontoao]\n";
        $configIni .= "url = $pontoaoUrl\n";
        $configIni .= "api_key = $rawKey\n";
        $configIni .= "tenant_id = $tenantId\n";
        $configIni .= "\n[agent]\n";
        $configIni .= "poll_interval_seconds = 300\n";
        $configIni .= "log_file = agent.log\n";
        $configIni .= "state_db = state.db\n";

        // Gerar install.bat — instala o .exe pré-compilado como serviço Windows via NSSM
        $installBat  = "@echo off\r\n";
        $installBat .= "echo A instalar o Agente PontoAO...\r\n";
        $installBat .= "set AGENT_DIR=%~dp0\r\n\r\n";
        $installBat .= "where nssm >nul 2>&1\r\n";
        $installBat .= "if %errorlevel% neq 0 (\r\n";
        $installBat .= "    echo A descarregar NSSM...\r\n";
        $installBat .= "    powershell -Command \"Invoke-WebRequest -Uri 'https://nssm.cc/release/nssm-2.24.zip' -OutFile '%TEMP%\\nssm.zip'; Expand-Archive -Path '%TEMP%\\nssm.zip' -DestinationPath '%TEMP%\\nssm' -Force; Copy-Item '%TEMP%\\nssm\\nssm-2.24\\win64\\nssm.exe' 'C:\\Windows\\System32\\nssm.exe'\"\r\n";
        $installBat .= ")\r\n\r\n";
        $installBat .= "nssm install PontoAO-Agent \"%AGENT_DIR%pontoao-agent.exe\"\r\n";
        $installBat .= "nssm set PontoAO-Agent AppDirectory \"%AGENT_DIR%\"\r\n";
        $installBat .= "nssm set PontoAO-Agent AppRestartDelay 10000\r\n";
        $installBat .= "nssm set PontoAO-Agent AppStdout \"%AGENT_DIR%agent.log\"\r\n";
        $installBat .= "nssm set PontoAO-Agent AppStderr \"%AGENT_DIR%agent.log\"\r\n";
        $installBat .= "nssm start PontoAO-Agent\r\n\r\n";
        $installBat .= "echo Agente PontoAO instalado com sucesso!\r\n";
        $installBat .= "pause\r\n";

        $uninstallBat  = "@echo off\r\n";
        $uninstallBat .= "nssm stop PontoAO-Agent\r\n";
        $uninstallBat .= "nssm remove PontoAO-Agent confirm\r\n";
        $uninstallBat .= "echo Agente removido.\r\n";
        $uninstallBat .= "pause\r\n";

        // Montar o .zip
        $zipPath = sys_get_temp_dir() . '/pontoao-agent-' . $tenantId . '-' . time() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile('/var/www/saas/public/install/pontoao-agent.exe', 'pontoao-agent.exe');
        $zip->addFromString('config.ini', $configIni);
        $zip->addFromString('install.bat', $installBat);
        $zip->addFromString('uninstall.bat', $uninstallBat);
        $zip->close();

        $zipContent = file_get_contents($zipPath);
        unlink($zipPath);

        $response->getBody()->write($zipContent);

        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', "attachment; filename=\"pontoao-agent-$tenantId.zip\"")
            ->withHeader('Content-Length', (string) strlen($zipContent))
            ->withStatus(200);
    }

    /**
     * POST /api/terminais
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub  = TenantResolver::resolve();
        $db   = Database::tenant($sub);
        $body = $request->getParsedBody();

        $nome = trim($body['nome'] ?? '');
        $ip   = trim($body['ip'] ?? '');

        if (empty($nome) || empty($ip)) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Nome e IP são obrigatórios.']);
        }

        $stmt = $db->prepare("
            INSERT INTO relogios (nome, localizacao, ip, porta, modelo, device_id, default_departamento_id, activo, api_key_hash)
            VALUES (:nome, :loc, :ip, :porta, :modelo, :device_id, :dep_id, 1, :key)
        ");

        $stmt->execute([
            ':nome'    => $nome,
            ':loc'     => $body['localizacao'] ?? null,
            ':ip'      => $ip,
            ':porta'   => (int) ($body['porta'] ?? 4370),
            ':modelo'  => $body['modelo'] ?? 'zkteco',
            ':device_id' => trim($body['device_id'] ?? '') ?: null,
            ':dep_id'  => $body['default_departamento_id'] ? (int) $body['default_departamento_id'] : null,
            ':key'     => hash('sha256', bin2hex(random_bytes(16))) // API key inicial aleatória
        ]);

        return $this->json($response, 201, ['mensagem' => 'Terminal registado com sucesso.', 'id' => $db->lastInsertId()]);
    }

    /**
     * GET /api/terminais/{id}
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->prepare("SELECT * FROM relogios WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $terminal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$terminal) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Terminal não encontrado.']);
        }

        // Departamentos associados
        $stmtDep = $db->prepare("
            SELECT d.id, d.nome
            FROM departamentos d
            JOIN relogio_departamentos rd ON d.id = rd.departamento_id
            WHERE rd.relogio_id = :id
        ");
        $stmtDep->execute([':id' => $id]);
        $terminal['departamentos'] = $stmtDep->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $terminal]);
    }

    /**
     * PUT /api/terminais/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $sub  = TenantResolver::resolve();
        $db   = Database::tenant($sub);
        $body = $request->getParsedBody();

        $nome = trim($body['nome'] ?? '');
        $ip   = trim($body['ip'] ?? '');

        if (empty($nome) || empty($ip)) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Nome e IP são obrigatórios.']);
        }

        $stmt = $db->prepare("
            UPDATE relogios
            SET nome = :nome, localizacao = :loc, ip = :ip, porta = :porta,
                modelo = :modelo, device_id = :device_id, default_departamento_id = :dep_id
            WHERE id = :id
        ");

        $stmt->execute([
            ':nome'    => $nome,
            ':loc'     => $body['localizacao'] ?? null,
            ':ip'      => $ip,
            ':porta'   => (int) ($body['porta'] ?? 4370),
            ':modelo'  => $body['modelo'] ?? 'zkteco',
            ':device_id' => trim($body['device_id'] ?? '') ?: null,
            ':dep_id'  => $body['default_departamento_id'] ? (int) $body['default_departamento_id'] : null,
            ':id'      => $id
        ]);

        return $this->json($response, 200, ['mensagem' => 'Terminal actualizado com sucesso.']);
    }

    /**
     * DELETE /api/terminais/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $db->prepare("DELETE FROM relogios WHERE id = :id")->execute([':id' => $id]);

        return $this->json($response, 200, ['mensagem' => 'Terminal removido com sucesso.']);
    }

    /**
     * GET /api/terminais/{id}/departamentos
     */
    public function listarDepartamentos(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->prepare("
            SELECT d.id, d.nome
            FROM departamentos d
            JOIN relogio_departamentos rd ON d.id = rd.departamento_id
            WHERE rd.relogio_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * POST /api/terminais/{id}/departamentos
     */
    public function associarDepartamento(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id    = (int) $args['id'];
        $body  = $request->getParsedBody();
        $depId = (int) ($body['departamento_id'] ?? 0);

        if (!$depId) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'ID do departamento é obrigatório.']);
        }

        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->prepare("INSERT IGNORE INTO relogio_departamentos (relogio_id, departamento_id) VALUES (:rid, :did)");
        $stmt->execute([':rid' => $id, ':did' => $depId]);

        return $this->json($response, 201, ['mensagem' => 'Departamento associado com sucesso.']);
    }

    /**
     * DELETE /api/terminais/{id}/departamentos/{dep_id}
     */
    public function removerDepartamento(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id    = (int) $args['id'];
        $depId = (int) $args['dep_id'];
        $sub   = TenantResolver::resolve();
        $db    = Database::tenant($sub);

        $db->prepare("DELETE FROM relogio_departamentos WHERE relogio_id = :rid AND departamento_id = :did")
           ->execute([':rid' => $id, ':did' => $depId]);

        return $this->json($response, 200, ['mensagem' => 'Associação removida com sucesso.']);
    }

    /**
     * GET /api/terminais/{id}/ping
     */
    public function ping(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->prepare("SELECT ip, porta FROM relogios WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $rel = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rel) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Terminal não encontrado.']);
        }

        $stmt2 = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, ultimo_heartbeat, NOW()) as seg_atras FROM relogios WHERE id = :id");
        $stmt2->execute([':id' => $id]);
        $hb = $stmt2->fetch(PDO::FETCH_ASSOC);
        $segAtras = $hb ? (int) $hb['seg_atras'] : PHP_INT_MAX;
        if ($segAtras <= 7200) {
            $minAtras = round($segAtras / 60, 1);
            return $this->json($response, 200, [
                'online'   => true,
                'latencia' => $minAtras . 'min',
                'mensagem' => "Terminal online (último contacto há {$minAtras} min)."
            ]);
        }
        return $this->json($response, 200, [
            'online'   => false,
            'erro'     => 'Sem heartbeat recente',
            'mensagem' => 'Terminal offline — sem contacto há mais de 2 horas.'
        ]);
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
