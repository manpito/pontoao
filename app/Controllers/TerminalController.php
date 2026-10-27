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
    private const MODELOS_VALIDOS = ['zkteco', 'facepro', 'dahua', 'outro'];

    /**
     * Lê e valida os campos de ligação ao relógio Dahua.
     * Devolve ['erro' => string|null, 'url' => ?string, 'user' => ?string, 'pw_enc' => ?string].
     * pw_enc é null quando a palavra-passe vem vazia (no update, mantém a actual).
     */
    private function camposDispositivo(array $body): array
    {
        $url = trim((string) ($body['device_url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://[^\s/]+/?$#', $url)) {
            return ['erro' => 'URL do relógio inválido. Exemplo: http://192.168.100.101', 'url' => null, 'user' => null, 'pw_enc' => null];
        }
        $url  = $url !== '' ? rtrim($url, '/') : null;
        $user = trim((string) ($body['device_user'] ?? ''));
        $user = $user !== '' ? $user : null;
        $pw   = (string) ($body['device_password'] ?? '');

        return [
            'erro'   => null,
            'url'    => $url,
            'user'   => $user,
            'pw_enc' => $pw !== '' ? $this->cifrarPasswordDispositivo($pw) : null,
        ];
    }

    /**
     * Cifra a palavra-passe do relógio no formato que AgentController decifra
     * (aes-256-cbc, chave APP_KEY, IV = primeiros 16 caracteres da APP_KEY).
     */
    private function cifrarPasswordDispositivo(string $pw): string
    {
        $appKey = $_ENV['APP_KEY'] ?? '';
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY não configurada no .env — não é possível cifrar a palavra-passe do relógio.');
        }
        $enc = openssl_encrypt($pw, 'aes-256-cbc', $appKey, 0, substr($appKey, 0, 16));
        if ($enc === false) {
            throw new \RuntimeException('Falha ao cifrar a palavra-passe do relógio.');
        }
        return $enc;
    }

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
            $d['tem_password'] = !empty($d['device_password_enc']);
            unset($d['device_password_enc'], $d['api_key_hash']);
        }
        unset($d);

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
        // LIMITAÇÃO CONHECIDA: usa APP_URL global, assume um único domínio para todos os tenants.
        // Antes de activar esta funcionalidade para outro tenant além da FTL Angola,
        // implementar resolução de domínio por tenant (ver tabela de tenants na base master).
        $pontoaoUrl = rtrim($_ENV['APP_URL'] ?? 'https://rh.ftl-angola.net', '/');

        // Gerar chave nova para esta instalação, ligada a este tenant (tabela agent_chaves na base master).
        // Só o sha256 fica guardado; a chave em claro existe apenas no config.ini do .zip.
        // Uma instalação por tenant: as chaves anteriores deste tenant ficam revogadas.
        $rawKey  = bin2hex(random_bytes(32)); // 64 caracteres hex
        $keyHash = hash('sha256', $rawKey);

        $master = Database::master();
        $stmtTenant = $master->prepare("SELECT id FROM tenants WHERE subdominio = :sub LIMIT 1");
        $stmtTenant->execute([':sub' => $sub]);
        $tenantMasterId = $stmtTenant->fetchColumn();

        if ($tenantMasterId === false) {
            return $this->json($response, 500, ['erro' => true, 'mensagem' => 'Tenant não encontrado na base master.']);
        }

        $agora = date('Y-m-d H:i:s');
        try {
            $master->beginTransaction();
            $master->prepare("UPDATE agent_chaves SET revogada_em = :agora WHERE tenant_id = :tid AND revogada_em IS NULL")
                   ->execute([':agora' => $agora, ':tid' => (int) $tenantMasterId]);
            $master->prepare("
                INSERT INTO agent_chaves (tenant_id, chave_hash, relogio_id, criado_por_utilizador_id, criado_em)
                VALUES (:tid, :hash, :relogio, :utilizador, :agora)
            ")->execute([
                ':tid'        => (int) $tenantMasterId,
                ':hash'       => $keyHash,
                ':relogio'    => $id,
                ':utilizador' => $request->getAttribute('auth_user_id'),
                ':agora'      => $agora,
            ]);
            $master->commit();
        } catch (\Throwable $e) {
            if ($master->inTransaction()) {
                $master->rollBack();
            }
            return $this->json($response, 500, ['erro' => true, 'mensagem' => 'Não foi possível gerar a chave do agente.']);
        }

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

        $modelo = $body['modelo'] ?? 'zkteco';
        if (!in_array($modelo, self::MODELOS_VALIDOS, true)) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Modelo inválido. Valores aceites: zkteco, facepro, dahua, outro.']);
        }
        $tipoProtocolo = $modelo === 'dahua' ? 'dahua' : 'zkteco';

        $disp = $this->camposDispositivo($body);
        if ($disp['erro'] !== null) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => $disp['erro']]);
        }

        $stmt = $db->prepare("
            INSERT INTO relogios (nome, localizacao, ip, porta, modelo, tipo_protocolo, device_id, device_url, device_user, device_password_enc, default_departamento_id, activo, api_key_hash)
            VALUES (:nome, :loc, :ip, :porta, :modelo, :tipo_protocolo, :device_id, :device_url, :device_user, :device_password_enc, :dep_id, 1, :key)
        ");

        $stmt->execute([
            ':nome'    => $nome,
            ':loc'     => $body['localizacao'] ?? null,
            ':ip'      => $ip,
            ':porta'   => (int) ($body['porta'] ?? 4370),
            ':modelo'  => $modelo,
            ':tipo_protocolo' => $tipoProtocolo,
            ':device_id' => trim($body['device_id'] ?? '') ?: null,
            ':device_url' => $disp['url'],
            ':device_user' => $disp['user'],
            ':device_password_enc' => $disp['pw_enc'],
            ':dep_id'  => !empty($body['default_departamento_id']) ? (int) $body['default_departamento_id'] : null,
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

        $terminal['tem_password'] = !empty($terminal['device_password_enc']);
        unset($terminal['device_password_enc'], $terminal['api_key_hash']);

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

        $modelo = $body['modelo'] ?? 'zkteco';
        if (!in_array($modelo, self::MODELOS_VALIDOS, true)) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Modelo inválido. Valores aceites: zkteco, facepro, dahua, outro.']);
        }

        $disp = $this->camposDispositivo($body);
        if ($disp['erro'] !== null) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => $disp['erro']]);
        }

        // tipo_protocolo: passa a 'dahua' quando o modelo é dahua; volta a 'zkteco'
        // só se estava em 'dahua' e o modelo deixou de o ser; nos outros casos mantém-se.
        // device_password_enc: palavra-passe vazia no formulário mantém a actual.
        $stmt = $db->prepare("
            UPDATE relogios
            SET nome = :nome, localizacao = :loc, ip = :ip, porta = :porta,
                modelo = :modelo,
                tipo_protocolo = CASE
                    WHEN :modelo_tp = 'dahua' THEN 'dahua'
                    WHEN tipo_protocolo = 'dahua' THEN 'zkteco'
                    ELSE tipo_protocolo
                END,
                device_id = :device_id, default_departamento_id = :dep_id,
                device_url = :device_url, device_user = :device_user,
                device_password_enc = COALESCE(:device_password_enc, device_password_enc)
            WHERE id = :id
        ");

        $stmt->execute([
            ':nome'    => $nome,
            ':loc'     => $body['localizacao'] ?? null,
            ':ip'      => $ip,
            ':porta'   => (int) ($body['porta'] ?? 4370),
            ':modelo'  => $modelo,
            ':modelo_tp' => $modelo,
            ':device_id' => trim($body['device_id'] ?? '') ?: null,
            ':device_url' => $disp['url'],
            ':device_user' => $disp['user'],
            ':device_password_enc' => $disp['pw_enc'],
            ':dep_id'  => !empty($body['default_departamento_id']) ? (int) $body['default_departamento_id'] : null,
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
