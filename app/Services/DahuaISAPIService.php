<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use RuntimeException;

class DahuaISAPIService
{
    protected function log(string $message): void
    {
        $logFile = '/var/www/saas/logs/dahua_sync.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    }

    protected function request(string $method, string $url, string $user, string $password, ?array $payload = null): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($ch, CURLOPT_USERPWD, "$user:$password");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $headers = [];
        if ($payload !== null) {
            $jsonPayload = json_encode($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            $headers[] = 'Content-Type: application/json';
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("CURL Error: $error");
        }

        return [
            'code' => $httpCode,
            'body' => $response
        ];
    }

    public function sincronizarFuncionario(string $deviceUrl, string $deviceUser, string $devicePassword, array $funcionario): bool
    {
        $numero = $funcionario['numero_funcionario'];
        $nome = $funcionario['nome'];

        $url = rtrim($deviceUrl, '/') . '/ISAPI/AccessControl/UserInfo/Record';

        $payload = [
            'UserInfo' => [
                'employeeNo' => $numero,
                'name' => $nome,
                'userType' => 'normal',
                'Valid' => [
                    'enable' => true,
                    'beginTime' => '2000-01-01T00:00:00',
                    'endTime' => '2037-12-31T23:59:59'
                ],
                'doorRight' => '1',
                'RightPlan' => [
                    [
                        'doorNo' => 1,
                        'planTemplateNo' => '1'
                    ]
                ]
            ]
        ];

        try {
            $response = $this->request('PUT', $url, $deviceUser, $devicePassword, $payload);

            if ($response['code'] >= 200 && $response['code'] < 300) {
                $this->log("SUCCESS: sync funcionario $numero to $deviceUrl");
                return true;
            } else {
                $this->log("FAILED: sync funcionario $numero to $deviceUrl (HTTP {$response['code']})");
                return false;
            }
        } catch (Exception $e) {
            $this->log("ERROR: sync funcionario $numero to $deviceUrl failed: " . $e->getMessage());
            throw $e;
        }
    }

    public function apagarFuncionario(string $deviceUrl, string $deviceUser, string $devicePassword, string $numeroFuncionario): bool
    {
        $url = rtrim($deviceUrl, '/') . '/ISAPI/AccessControl/UserInfo/Delete';

        $payload = [
            'UserInfoDelCond' => [
                'EmployeeNoList' => [
                    ['employeeNo' => $numeroFuncionario]
                ]
            ]
        ];

        try {
            $response = $this->request('PUT', $url, $deviceUser, $devicePassword, $payload);

            if ($response['code'] >= 200 && $response['code'] < 300) {
                $this->log("SUCCESS: delete funcionario $numeroFuncionario from $deviceUrl");
                return true;
            } else {
                $this->log("FAILED: delete funcionario $numeroFuncionario from $deviceUrl (HTTP {$response['code']})");
                return false;
            }
        } catch (Exception $e) {
            $this->log("ERROR: delete funcionario $numeroFuncionario from $deviceUrl failed: " . $e->getMessage());
            throw $e;
        }
    }

    public function funcionarioExiste(string $deviceUrl, string $deviceUser, string $devicePassword, string $numeroFuncionario): bool
    {
        $url = rtrim($deviceUrl, '/') . '/ISAPI/AccessControl/UserInfo/Search';

        $payload = [
            'UserInfoSearchCond' => [
                'searchID' => '1',
                'searchResultPosition' => 0,
                'maxResults' => 1,
                'EmployeeNoList' => [
                    ['employeeNo' => $numeroFuncionario]
                ]
            ]
        ];

        try {
            $response = $this->request('POST', $url, $deviceUser, $devicePassword, $payload);

            if ($response['code'] === 404) {
                return false;
            }

            if ($response['code'] >= 200 && $response['code'] < 300) {
                // Analisar a resposta para ver se realmente retornou algo, ou apenas assumir true se sucesso HTTP
                return true;
            }

            $this->log("FAILED: search funcionario $numeroFuncionario in $deviceUrl (HTTP {$response['code']})");
            return false;
        } catch (Exception $e) {
            $this->log("ERROR: search funcionario $numeroFuncionario in $deviceUrl failed: " . $e->getMessage());
            throw $e;
        }
    }
}
