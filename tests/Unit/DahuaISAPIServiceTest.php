<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\DahuaISAPIService;
use RuntimeException;

class DahuaISAPIServiceTest extends TestCase
{
    private DahuaISAPIService $service;
    private string $deviceUrl = 'http://localhost:8080';
    private string $deviceUser = 'admin';
    private string $devicePassword = 'password';

    protected function setUp(): void
    {
        $this->service = new class extends DahuaISAPIService {
            public array $mockedResponses = [];
            public array $requestsMade = [];

            protected function request(string $method, string $url, string $user, string $password, ?array $payload = null): array
            {
                $this->requestsMade[] = [
                    'method' => $method,
                    'url' => $url,
                    'payload' => $payload
                ];

                if (isset($this->mockedResponses[$url])) {
                    $response = $this->mockedResponses[$url];
                    if ($response instanceof \Exception) {
                        throw $response;
                    }
                    return $response;
                }

                return parent::request($method, $url, $user, $password, $payload);
            }

            protected function log(string $message): void
            {
                // Silenciar logs nos testes
            }
        };
    }

    public function testSincronizarFuncionarioComSucesso(): void
    {
        $this->service->mockedResponses[$this->deviceUrl . '/ISAPI/AccessControl/UserInfo/Record'] = [
            'code' => 200,
            'body' => '{"status": "OK"}'
        ];

        $funcionario = ['numero_funcionario' => '0001', 'nome' => 'MARCO CARNEIRO'];
        $result = $this->service->sincronizarFuncionario($this->deviceUrl, $this->deviceUser, $this->devicePassword, $funcionario);

        $this->assertTrue($result);
        $this->assertCount(1, $this->service->requestsMade);
        $this->assertEquals('PUT', $this->service->requestsMade[0]['method']);
    }

    public function testSincronizarFuncionarioComTimeout(): void
    {
        $this->service->mockedResponses[$this->deviceUrl . '/ISAPI/AccessControl/UserInfo/Record'] = new RuntimeException("CURL Error: Operation timed out after 10000 milliseconds");

        $funcionario = ['numero_funcionario' => '0001', 'nome' => 'MARCO CARNEIRO'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("CURL Error: Operation timed out after 10000 milliseconds");

        $this->service->sincronizarFuncionario($this->deviceUrl, $this->deviceUser, $this->devicePassword, $funcionario);
    }

    public function testFuncionarioExisteRetornaTrue(): void
    {
        $this->service->mockedResponses[$this->deviceUrl . '/ISAPI/AccessControl/UserInfo/Search'] = [
            'code' => 200,
            'body' => '{"UserInfoSearchCond": {"searchID": "1"}}'
        ];

        $result = $this->service->funcionarioExiste($this->deviceUrl, $this->deviceUser, $this->devicePassword, '0001');

        $this->assertTrue($result);
        $this->assertCount(1, $this->service->requestsMade);
        $this->assertEquals('POST', $this->service->requestsMade[0]['method']);
    }

    public function testFuncionarioExisteRetornaFalseEm404(): void
    {
        $this->service->mockedResponses[$this->deviceUrl . '/ISAPI/AccessControl/UserInfo/Search'] = [
            'code' => 404,
            'body' => ''
        ];

        $result = $this->service->funcionarioExiste($this->deviceUrl, $this->deviceUser, $this->devicePassword, '0001');

        $this->assertFalse($result);
    }

    public function testApagarFuncionarioComSucesso(): void
    {
        $this->service->mockedResponses[$this->deviceUrl . '/ISAPI/AccessControl/UserInfo/Delete'] = [
            'code' => 200,
            'body' => '{"status": "OK"}'
        ];

        $result = $this->service->apagarFuncionario($this->deviceUrl, $this->deviceUser, $this->devicePassword, '0001');

        $this->assertTrue($result);
        $this->assertCount(1, $this->service->requestsMade);
        $this->assertEquals('PUT', $this->service->requestsMade[0]['method']);
    }
}
