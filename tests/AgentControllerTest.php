<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\AgentController;
use App\Middleware\AgentAuthMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use PDO;

class AgentControllerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        require_once __DIR__ . '/bootstrap_db.php';
        $this->pdo = bootstrap_db();

        // Seed some data
        $this->pdo->exec("
            INSERT INTO departamentos (id, nome) VALUES (1, 'IT'), (2, 'RH');

            INSERT INTO funcionarios (id, numero_funcionario, nome_completo, nome, departamento_id, estado)
            VALUES (1, '0001', 'MARCO CARNEIRO', 'Marco', 2, 'activo');

            INSERT INTO funcionarios (id, numero_funcionario, nome_completo, nome, departamento_id, estado)
            VALUES (2, '0002', 'JOAO SILVA', 'Joao', 1, 'inactivo');
        ");

        $appKey = '12345678901234567890123456789012'; // 32 chars
        $_ENV['APP_KEY'] = $appKey;
    }

    /**
     * Base master mínima em SQLite: tenants + agent_chaves.
     */
    private function criarMaster(): PDO
    {
        $master = new PDO('sqlite::memory:');
        $master->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $master->exec("
            CREATE TABLE tenants (
                id INTEGER PRIMARY KEY,
                subdominio TEXT NOT NULL
            );

            CREATE TABLE agent_chaves (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                chave_hash TEXT NOT NULL UNIQUE,
                relogio_id INTEGER NULL,
                criado_por_utilizador_id INTEGER NULL,
                criado_em TEXT NOT NULL,
                revogada_em TEXT NULL,
                ultimo_uso_em TEXT NULL
            );

            INSERT INTO tenants (id, subdominio) VALUES (1, 'tenant1'), (2, 'tenant2');
        ");
        return $master;
    }

    private function inserirChave(PDO $master, int $tenantId, string $chave, ?string $revogadaEm = null): void
    {
        $stmt = $master->prepare("
            INSERT INTO agent_chaves (tenant_id, chave_hash, criado_em, revogada_em)
            VALUES (:tid, :hash, '2026-10-27 10:00:00', :rev)
        ");
        $stmt->execute([':tid' => $tenantId, ':hash' => hash('sha256', $chave), ':rev' => $revogadaEm]);
    }

    /**
     * Middleware com a base master e a base do tenant substituídas por SQLite.
     */
    private function middlewareComMaster(PDO $master): AgentAuthMiddleware
    {
        return new class($master, $this->pdo) extends AgentAuthMiddleware {
            public function __construct(private PDO $masterTeste, private PDO $tenantTeste)
            {
            }

            protected function master(): PDO
            {
                return $this->masterTeste;
            }

            protected function abrirTenant(string $subdominio): PDO
            {
                return $this->tenantTeste;
            }
        };
    }

    private function requestComCabecalhos(string $authorization, string $tenant): ServerRequestInterface
    {
        $request = $this->createRequest();
        $request->method('getHeaderLine')->willReturnMap([
            ['Authorization', $authorization],
            ['X-Tenant-ID', $tenant],
        ]);
        $request->method('withAttribute')->willReturnSelf();
        return $request;
    }

    private function createRequestWithBody(string $body): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $request->method('getBody')->willReturn($stream);
        $request->method('getParsedBody')->willReturn(json_decode($body, true));
        $request->method('getHeaderLine')->willReturnMap([
            ['Content-Type', 'application/json']
        ]);
        return $request;
    }

    private function createRequest(): ServerRequestInterface
    {
        return $this->createMock(ServerRequestInterface::class);
    }

    private function createResponse(): ResponseInterface
    {
        return new \Slim\Psr7\Response();
    }

    // 1. GET /api/agent/funcionarios with valid API key -> 200
    // Test the controller directly for the DB behavior.
    public function testGetFuncionariosComApiKeyValida(): void
    {
        $controller = new AgentController();
        $request = $this->createRequest();
        $request->method('getAttribute')->with('tenant_db')->willReturn($this->pdo);

        $response = $controller->getFuncionarios($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('funcionarios', $body);
        $this->assertCount(2, $body['funcionarios']);
        $this->assertEquals('MARCO CARNEIRO', $body['funcionarios'][0]['nome_completo']);
    }

    // 2. Middleware sem chave ou com chave desconhecida -> 401
    public function testMiddlewareSemApiKey(): void
    {
        $master = $this->criarMaster();
        $this->inserirChave($master, 1, 'chave-tenant1');
        $middleware = $this->middlewareComMaster($master);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        // Sem authorization
        $response = $middleware->process($this->requestComCabecalhos('', 'tenant1'), $handler);
        $this->assertEquals(401, $response->getStatusCode());

        // Com chave desconhecida
        $response2 = $middleware->process($this->requestComCabecalhos('Bearer invalid-key', 'tenant1'), $handler);
        $this->assertEquals(401, $response2->getStatusCode());

        // Sem X-Tenant-ID
        $response3 = $middleware->process($this->requestComCabecalhos('Bearer chave-tenant1', ''), $handler);
        $this->assertEquals(401, $response3->getStatusCode());
    }

    // 2b. Chave válida do próprio tenant -> passa e regista o último uso
    public function testMiddlewareChaveValidaDoTenant(): void
    {
        $master = $this->criarMaster();
        $this->inserirChave($master, 1, 'chave-tenant1');
        $middleware = $this->middlewareComMaster($master);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturn(new \Slim\Psr7\Response(200));

        $response = $middleware->process($this->requestComCabecalhos('Bearer chave-tenant1', 'tenant1'), $handler);
        $this->assertEquals(200, $response->getStatusCode());

        $ultimoUso = $master->query("SELECT ultimo_uso_em FROM agent_chaves WHERE tenant_id = 1")->fetchColumn();
        $this->assertNotNull($ultimoUso);
    }

    // 2c. Chave de um tenant usada com o X-Tenant-ID de outro -> 401
    public function testMiddlewareChaveDeOutroTenant(): void
    {
        $master = $this->criarMaster();
        $this->inserirChave($master, 1, 'chave-tenant1');
        $middleware = $this->middlewareComMaster($master);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($this->requestComCabecalhos('Bearer chave-tenant1', 'tenant2'), $handler);
        $this->assertEquals(401, $response->getStatusCode());
    }

    // 2d. Chave revogada -> 401
    public function testMiddlewareChaveRevogada(): void
    {
        $master = $this->criarMaster();
        $this->inserirChave($master, 1, 'chave-antiga', '2026-10-27 11:00:00');
        $middleware = $this->middlewareComMaster($master);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($this->requestComCabecalhos('Bearer chave-antiga', 'tenant1'), $handler);
        $this->assertEquals(401, $response->getStatusCode());
    }

    // 2e. A chave global antiga do .env deixa de ser aceite
    public function testMiddlewareIgnoraChaveGlobalDoEnv(): void
    {
        $_ENV['AGENT_API_KEY_HASH'] = hash('sha256', 'agent-secret');
        $master = $this->criarMaster();
        $middleware = $this->middlewareComMaster($master);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $middleware->process($this->requestComCabecalhos('Bearer agent-secret', 'tenant1'), $handler);
        $this->assertEquals(401, $response->getStatusCode());

        unset($_ENV['AGENT_API_KEY_HASH']);
    }

    // 3. GET /api/agent/relogios with valid API key -> 200 and decrypted password
    public function testGetRelogiosComDecifracaoDePassword(): void
    {
        $appKey = $_ENV['APP_KEY'];
        $iv = substr($appKey, 0, 16);
        $encPassword = openssl_encrypt('Ab123456', 'aes-256-cbc', $appKey, 0, $iv);

        $this->pdo->exec("
            INSERT INTO relogios (id, nome, device_url, device_user, device_password_enc, tipo_protocolo, activo)
            VALUES (3, 'Dahua DHI-ASI6214S', 'http://192.168.100.144', 'admin', '{$encPassword}', 'dahua', 1);

            INSERT INTO relogio_departamentos (relogio_id, departamento_id) VALUES (3, 1), (3, 2);
        ");

        $controller = new AgentController();
        $request = $this->createRequest();
        $request->method('getAttribute')->with('tenant_db')->willReturn($this->pdo);

        $response = $controller->getRelogios($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('relogios', $body);
        $this->assertCount(1, $body['relogios']);
        $this->assertEquals('Ab123456', $body['relogios'][0]['device_password']);

        // Assert departamentos are correctly parsed as int array
        $this->assertIsArray($body['relogios'][0]['departamentos']);
        $this->assertEquals([1, 2], $body['relogios'][0]['departamentos']);
        $this->assertArrayNotHasKey('device_password_enc', $body['relogios'][0]);
    }

    // 4. POST /api/agent/sync-log with valid payload -> 200
    public function testPostSyncLogPayloadValido(): void
    {
        $controller = new AgentController();

        $payload = json_encode([
            "relogio_id" => 3,
            "funcionario_id" => 1,
            "operacao" => "insert",
            "sucesso" => true,
            "erro" => null,
            "timestamp" => "2026-09-10 09:00:00"
        ]);
        $request = $this->createRequestWithBody($payload);
        $request->method('getAttribute')->with('tenant_db')->willReturn($this->pdo);

        $response = $controller->postSyncLog($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('ok', $body['result']);

        $stmt = $this->pdo->query("SELECT * FROM agent_sync_log");
        $logs = $stmt->fetchAll();
        $this->assertCount(1, $logs);
        $this->assertEquals(3, $logs[0]['relogio_id']);
        $this->assertEquals(1, $logs[0]['sucesso']);
    }

    // 5. POST /api/agent/sync-log with invalid payload -> 200 (never fails)
    public function testPostSyncLogPayloadInvalido(): void
    {
        $controller = new AgentController();

        // Relogio_id missing
        $payload = json_encode([
            "operacao" => "insert"
        ]);
        $request = $this->createRequestWithBody($payload);
        $request->method('getAttribute')->with('tenant_db')->willReturn($this->pdo);

        $response = $controller->postSyncLog($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertEquals('ok', $body['result']);

        // Check if anything inserted or not - if missing required fields, it will fail and be ignored
        $stmt = $this->pdo->query("SELECT * FROM agent_sync_log");
        $logs = $stmt->fetchAll();
        $this->assertCount(0, $logs); // Since it was invalid, insert failed silently
    }
}
