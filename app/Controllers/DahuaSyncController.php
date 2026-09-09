<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use App\Services\DahuaISAPIService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class DahuaSyncController
{
    private DahuaISAPIService $dahuaService;
    private string $appKey;

    public function __construct()
    {
        $this->dahuaService = new DahuaISAPIService();
        $this->appKey = $_ENV['APP_KEY'] ?? 'default_app_key_if_missing';
    }

    private function decryptPassword(string $encrypted): string
    {
        return openssl_decrypt($encrypted, 'aes-256-cbc', $this->appKey, 0, substr($this->appKey, 0, 16));
    }

    private function jsonResponse(ResponseInterface $response, $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    public function syncFuncionario(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenantId = TenantResolver::resolve();
        $db = Database::tenant($tenantId);
        $id = (int)$args['id'];

        $stmtFunc = $db->prepare("SELECT numero_funcionario, nome, estado FROM funcionarios WHERE id = :id");
        $stmtFunc->execute(['id' => $id]);
        $funcionario = $stmtFunc->fetch(PDO::FETCH_ASSOC);

        if (!$funcionario) {
            return $this->jsonResponse($response, ['error' => 'Funcionário não encontrado'], 404);
        }

        $stmtRelogios = $db->query("SELECT * FROM relogios WHERE tipo_protocolo = 'dahua' AND activo = 1");
        $relogios = $stmtRelogios->fetchAll(PDO::FETCH_ASSOC);

        $resultados = [];

        foreach ($relogios as $relogio) {
            $deviceUrl = $relogio['device_url'];
            $deviceUser = $relogio['device_user'];
            $devicePasswordEnc = $relogio['device_password_enc'];

            if (!$deviceUrl || !$deviceUser || !$devicePasswordEnc) {
                $resultados[$relogio['id']] = ['status' => 'error', 'message' => 'Configurações do dispositivo incompletas'];
                continue;
            }

            $password = $this->decryptPassword($devicePasswordEnc);

            try {
                if ($funcionario['estado'] === 'activo') {
                    $sucesso = $this->dahuaService->sincronizarFuncionario($deviceUrl, $deviceUser, $password, $funcionario);
                } else {
                    $sucesso = $this->dahuaService->apagarFuncionario($deviceUrl, $deviceUser, $password, $funcionario['numero_funcionario']);
                }
                $resultados[$relogio['id']] = ['status' => $sucesso ? 'success' : 'error'];
            } catch (\Exception $e) {
                $resultados[$relogio['id']] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $this->jsonResponse($response, ['resultados' => $resultados]);
    }

    public function syncAll(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = TenantResolver::resolve() ?? 'default';

        $scriptPath = realpath(__DIR__ . '/../../scripts/dahua_sync_all.php');
        if (!$scriptPath) {
            return $this->jsonResponse($response, ['error' => 'Script de sincronização não encontrado'], 500);
        }

        $command = "php " . escapeshellarg($scriptPath) . " " . escapeshellarg($tenantId) . " > /dev/null 2>&1 &";
        exec($command);

        return $this->jsonResponse($response, ['status' => 'queued', 'message' => 'Sincronização global iniciada em background']);
    }

    public function deleteFuncionario(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenantId = TenantResolver::resolve();
        $db = Database::tenant($tenantId);
        $numeroFuncionario = $args['numero'];

        $stmtRelogios = $db->query("SELECT * FROM relogios WHERE tipo_protocolo = 'dahua' AND activo = 1");
        $relogios = $stmtRelogios->fetchAll(PDO::FETCH_ASSOC);

        $resultados = [];

        foreach ($relogios as $relogio) {
            $deviceUrl = $relogio['device_url'];
            $deviceUser = $relogio['device_user'];
            $devicePasswordEnc = $relogio['device_password_enc'];

            if (!$deviceUrl || !$deviceUser || !$devicePasswordEnc) {
                $resultados[$relogio['id']] = ['status' => 'error', 'message' => 'Configurações do dispositivo incompletas'];
                continue;
            }

            $password = $this->decryptPassword($devicePasswordEnc);

            try {
                $sucesso = $this->dahuaService->apagarFuncionario($deviceUrl, $deviceUser, $password, $numeroFuncionario);
                $resultados[$relogio['id']] = ['status' => $sucesso ? 'success' : 'error'];
            } catch (\Exception $e) {
                $resultados[$relogio['id']] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $this->jsonResponse($response, ['resultados' => $resultados]);
    }
}
