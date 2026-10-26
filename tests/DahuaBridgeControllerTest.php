<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\DahuaBridgeController;
use App\Services\TerminalProcessamentoService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

class DahuaBridgeControllerTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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
        return new \Slim\Psr7\Response();
    }

    private function injectar(DahuaBridgeController $controller, TerminalProcessamentoService $mock): void
    {
        $reflection = new \ReflectionClass($controller);
        $property = $reflection->getProperty('terminalService');
        $property->setAccessible(true);
        $property->setValue($controller, $mock);
    }

    private function picagem(array $extra = []): array
    {
        return [
            'Action' => 'Pulse',
            'Code'   => 'AccessControl',
            'Data'   => array_merge([
                'CardNo' => '132543',
                'Method' => 6,
                'SN'     => 'TEST-SN',
                'Status' => 1,
                'Type'   => 'Entry',
                'UTC'    => 1792766283,
                'UserID' => '132543',
            ], $extra),
            'Index' => 0,
        ];
    }

    public function testPayloadAccessControlValido()
    {
        $request = $this->createRequestWithBody(gzdeflate(json_encode($this->picagem())));

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->method('resolverTenantPorSN')->with('TEST-SN')->willReturn([
            $this->createMock(\PDO::class),
            ['id' => 1, 'device_id' => 'TEST-SN'],
        ]);
        $mockService->expects($this->once())->method('processarRegisto')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(fn ($r) => $r['UserID'] === '132543'
                    && $r['origem_dahua'] === 'relogio'
                    && $r['tipo_dahua'] === 'entrada')
            );
        $this->injectar($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"result":"ok"}', (string) $response->getBody());
    }

    public function testCorpoJsonFormatadoSemCompressao()
    {
        // Formato real dos relógios: JSON de texto formatado, apesar de Content-Encoding: deflate.
        $request = $this->createRequestWithBody(json_encode($this->picagem(), JSON_PRETTY_PRINT));

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->method('resolverTenantPorSN')->with('TEST-SN')->willReturn([
            $this->createMock(\PDO::class),
            ['id' => 1, 'device_id' => 'TEST-SN'],
        ]);
        $mockService->expects($this->once())->method('processarRegisto');
        $this->injectar($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testCorpoRawDeflate()
    {
        $request = $this->createRequestWithBody(gzdeflate(json_encode($this->picagem(), JSON_PRETTY_PRINT)));

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->method('resolverTenantPorSN')->willReturn([
            $this->createMock(\PDO::class),
            ['id' => 1, 'device_id' => 'TEST-SN'],
        ]);
        $mockService->expects($this->once())->method('processarRegisto');
        $this->injectar($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testPayloadDoorStatus()
    {
        $payload = json_encode([
            'Action' => 'Pulse',
            'Code'   => 'DoorStatus',
            'Data'   => ['SN' => 'TEST-SN', 'Status' => 'Open'],
        ]);
        $request = $this->createRequestWithBody($payload);

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->expects($this->never())->method('processarRegisto');
        $this->injectar($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testPayloadAccessControlSemUserID()
    {
        $request = $this->createRequestWithBody(json_encode($this->picagem(['UserID' => ''])));

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->expects($this->never())->method('processarRegisto');
        $this->injectar($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testTentativaFalhadaIgnorada()
    {
        $request = $this->createRequestWithBody(json_encode($this->picagem(['Status' => 0, 'ErrorCode' => 16])));

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->expects($this->never())->method('processarRegisto');
        $this->injectar($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testBodyNaoDescomprimivelNaoJSON()
    {
        $request = $this->createRequestWithBody('random binary non-json string');

        $controller = new DahuaBridgeController();

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testAutenticacaoMiddlewares()
    {
        $middleware = new \App\Middleware\DahuaBridgeAuthMiddleware();

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('');

        $handler = $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class);
        $response = clone $middleware->process($request, $handler);
        $this->assertEquals(401, $response->getStatusCode());

        $request2 = $this->createMock(ServerRequestInterface::class);
        $request2->method('getHeaderLine')->with('Authorization')->willReturn('Basic ' . base64_encode('wrong:pass'));

        $_ENV['DAHUA_WEBHOOK_USER'] = 'pontoao';
        $_ENV['DAHUA_WEBHOOK_PASSWORD'] = 'secret';

        $response2 = clone $middleware->process($request2, $handler);
        $this->assertEquals(401, $response2->getStatusCode());

        $request3 = $this->createMock(ServerRequestInterface::class);
        $request3->method('getHeaderLine')->with('Authorization')->willReturn('Basic ' . base64_encode('pontoao:secret'));

        $handler->method('handle')->willReturn(new \Slim\Psr7\Response(200));
        $response3 = clone $middleware->process($request3, $handler);
        $this->assertEquals(200, $response3->getStatusCode());
    }

    public function testPayloadComBiometricos()
    {
        $request = $this->createRequestWithBody(json_encode($this->picagem(['FingerPrintData' => base64_encode('dummy')])));

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->expects($this->never())->method('processarRegisto');
        $this->injectar($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testSNDesconhecido()
    {
        $request = $this->createRequestWithBody(json_encode($this->picagem(['SN' => 'UNKNOWN'])));

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->method('resolverTenantPorSN')->with('UNKNOWN')->willReturn([null, null]);
        $mockService->expects($this->never())->method('processarRegisto');
        $this->injectar($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testLoteOffline()
    {
        $lote = [
            'Events' => [
                array_merge($this->picagem(), ['DataSource' => 'Offline']),
                array_merge($this->picagem(['UTC' => 1792766400]), ['DataSource' => 'Offline']),
                ['Code' => 'DoorStatus', 'Data' => ['SN' => 'TEST-SN', 'Status' => 'Open']],
            ],
        ];
        $request = $this->createRequestWithBody(gzdeflate(json_encode($lote)));

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->method('resolverTenantPorSN')->willReturn([
            $this->createMock(\PDO::class),
            ['id' => 1, 'device_id' => 'TEST-SN'],
        ]);
        $mockService->expects($this->exactly(2))->method('processarRegisto');
        $this->injectar($controller, $mockService);

        $response = $controller->receive($request, $this->createResponse());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testTokenFormatoInvalido()
    {
        $request = $this->createRequestWithBody(json_encode($this->picagem()));

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->expects($this->never())->method('resolverRelogioPorToken');
        $mockService->expects($this->never())->method('processarRegisto');
        $this->injectar($controller, $mockService);

        $response = $controller->receiveToken($request, $this->createResponse(), ['token' => 'abc']);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testTokenDesconhecido()
    {
        $request = $this->createRequestWithBody(json_encode($this->picagem()));

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->method('resolverRelogioPorToken')->with(self::TOKEN)->willReturn([null, null]);
        $mockService->expects($this->never())->method('processarRegisto');
        $this->injectar($controller, $mockService);

        $response = $controller->receiveToken($request, $this->createResponse(), ['token' => self::TOKEN]);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testTokenComSNDiferente()
    {
        $request = $this->createRequestWithBody(json_encode($this->picagem(['SN' => 'OUTRO-SN'])));

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->method('resolverRelogioPorToken')->willReturn([
            $this->createMock(\PDO::class),
            ['id' => 5, 'device_id' => 'TEST-SN'],
        ]);
        $mockService->expects($this->never())->method('processarRegisto');
        $mockService->expects($this->never())->method('resolverTenantPorSN');
        $this->injectar($controller, $mockService);

        $response = $controller->receiveToken($request, $this->createResponse(), ['token' => self::TOKEN]);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testTokenValido()
    {
        $request = $this->createRequestWithBody(gzdeflate(json_encode($this->picagem())));

        $controller = new DahuaBridgeController();
        $mockService = $this->createMock(TerminalProcessamentoService::class);
        $mockService->method('resolverRelogioPorToken')->with(self::TOKEN)->willReturn([
            $this->createMock(\PDO::class),
            ['id' => 5, 'device_id' => 'TEST-SN'],
        ]);
        $mockService->expects($this->never())->method('resolverTenantPorSN');
        $mockService->expects($this->once())->method('processarRegisto');
        $this->injectar($controller, $mockService);

        $response = $controller->receiveToken($request, $this->createResponse(), ['token' => self::TOKEN]);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"result":"ok"}', (string) $response->getBody());
    }
}
