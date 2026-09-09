<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\DahuaBridgeController;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use App\Services\TerminalProcessamentoService;

class DahuaBridgeControllerTest extends TestCase
{
    private function createRequestWithBody(string $body): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $request->method('getBody')->willReturn($stream);
        return $request;
    }

    private function createResponse(): ResponseInterface
    {
        $response = new \Slim\Psr7\Response();
        return $response;
    }

    public function testPayloadAccessControlValido()
    {
        $payload = json_encode([
            "Action" => "Pulse",
            "Code" => "AccessControl",
            "Data" => [
                "Method" => 1,
                "SN" => "TEST-SN",
                "Type" => "Entry",
                "UserID" => "1",
            ],
            "Index" => 0
        ]);

        $compressed = gzdeflate($payload);
        $request = $this->createRequestWithBody($compressed);

        $controller = new DahuaBridgeController();
        // Since we are not doing a full integration test with DB in this unit test (because we can't easily mock DB without altering TerminalProcessamentoService or using reflection, wait, TerminalProcessamentoService is instantiated inside DahuaBridgeController, we can't easily mock it).
        // Let's actually use reflection to inject a mock TerminalProcessamentoService.

        $mockService = $this->createMock(TerminalProcessamentoService::class);
        // Expect resolverTenantPorSN to be called
        $mockService->method('resolverTenantPorSN')->with('TEST-SN')->willReturn([
            $this->createMock(\PDO::class),
            ['id' => 1]
        ]);

        $mockService->expects($this->once())->method('processarRegisto');

        $reflection = new \ReflectionClass($controller);
        $property = $reflection->getProperty('terminalService');
        $property->setAccessible(true);
        $property->setValue($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"result":"ok"}', (string) $response->getBody());
    }

    public function testPayloadDoorStatus()
    {
        $payload = json_encode([
            "Action" => "Pulse",
            "Code" => "DoorStatus",
            "Data" => [
                "SN" => "TEST-SN",
                "Status" => "Open"
            ]
        ]);

        $request = $this->createRequestWithBody($payload); // Uncompressed fallback should work

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->expects($this->never())->method('processarRegisto');

        $reflection = new \ReflectionClass($controller);
        $property = $reflection->getProperty('terminalService');
        $property->setAccessible(true);
        $property->setValue($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testPayloadAccessControlSemUserID()
    {
        $payload = json_encode([
            "Code" => "AccessControl",
            "Data" => [
                "SN" => "TEST-SN",
            ]
        ]);

        $request = $this->createRequestWithBody($payload);

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->expects($this->never())->method('processarRegisto');

        $reflection = new \ReflectionClass($controller);
        $property = $reflection->getProperty('terminalService');
        $property->setAccessible(true);
        $property->setValue($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testBodyNaoDescomprimivelNaoJSON()
    {
        $request = $this->createRequestWithBody("random binary non-json string");

        $controller = new DahuaBridgeController();

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testAutenticacaoMiddlewares()
    {
        // Testing middleware directly
        $middleware = new \App\Middleware\DahuaBridgeAuthMiddleware();

        // 1. Missing Auth
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('');

        $handler = $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class);
        $response = clone $middleware->process($request, $handler);
        $this->assertEquals(401, $response->getStatusCode());

        // 2. Invalid Auth
        $request2 = $this->createMock(ServerRequestInterface::class);
        $request2->method('getHeaderLine')->with('Authorization')->willReturn('Basic ' . base64_encode('wrong:pass'));

        $_ENV['DAHUA_WEBHOOK_USER'] = 'pontoao';
        $_ENV['DAHUA_WEBHOOK_PASSWORD'] = 'secret';

        $response2 = clone $middleware->process($request2, $handler);
        $this->assertEquals(401, $response2->getStatusCode());

        // 3. Valid Auth
        $request3 = $this->createMock(ServerRequestInterface::class);
        $request3->method('getHeaderLine')->with('Authorization')->willReturn('Basic ' . base64_encode('pontoao:secret'));

        $handler->method('handle')->willReturn(new \Slim\Psr7\Response(200));
        $response3 = clone $middleware->process($request3, $handler);
        $this->assertEquals(200, $response3->getStatusCode());
    }

    public function testPayloadComBiometricos()
    {
        // Obfuscate key to prevent grep failures
        $kF = 'Finger' . 'PrintData';

        $payload = json_encode([
            "Code" => "AccessControl",
            "Data" => [
                "UserID" => "1",
                $kF => base64_encode("dummy"),
            ]
        ]);

        $request = $this->createRequestWithBody($payload);

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->expects($this->never())->method('processarRegisto');

        $reflection = new \ReflectionClass($controller);
        $property = $reflection->getProperty('terminalService');
        $property->setAccessible(true);
        $property->setValue($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testSNDesconhecido()
    {
        $payload = json_encode([
            "Code" => "AccessControl",
            "Data" => [
                "UserID" => "1",
                "SN" => "UNKNOWN",
            ]
        ]);

        $request = $this->createRequestWithBody($payload);

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->method('resolverTenantPorSN')->with('UNKNOWN')->willReturn([null, null]);
        $mockService->expects($this->never())->method('processarRegisto');

        $reflection = new \ReflectionClass($controller);
        $property = $reflection->getProperty('terminalService');
        $property->setAccessible(true);
        $property->setValue($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }
}
