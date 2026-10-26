<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TerminalProcessamentoService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class DahuaBridgeController
{
    private TerminalProcessamentoService $terminalService;

    public function __construct()
    {
        $this->terminalService = new TerminalProcessamentoService();
    }

    /**
     * POST /dahua/events (rota antiga, autenticação Basic via DahuaBridgeAuthMiddleware).
     * Os relógios Dahua testados não enviam credenciais no Auto Upload; mantida por compatibilidade.
     * O relógio é resolvido pelo S/N do evento.
     */
    public function receive(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->processarPedido($request, $response, null);
    }

    /**
     * POST /dahua/events/{token}
     * O token (64 caracteres hexadecimais) identifica o relógio: o seu sha256 está em relogios.api_key_hash.
     * O S/N de cada evento tem de coincidir com o device_id desse relógio.
     */
    public function receiveToken(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = strtolower((string) ($args['token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return $response->withStatus(401);
        }

        [$db, $relogio] = $this->terminalService->resolverRelogioPorToken($token);
        if (!$db || !$relogio) {
            return $response->withStatus(401);
        }

        return $this->processarPedido($request, $response, [$db, $relogio]);
    }

    /**
     * Lê o corpo (deflate ou texto), aceita um evento isolado ou um lote {"Events":[...]}
     * e processa cada evento. Responde sempre 200 a um JSON válido, para o relógio não reenviar.
     */
    private function processarPedido(ServerRequestInterface $request, ResponseInterface $response, ?array $contexto): ResponseInterface
    {
        $raw = (string) $request->getBody();
        if ($raw === '') {
            $raw = (string) file_get_contents('php://input');
        }

        // Os relógios Dahua enviam "Content-Encoding: deflate" mas o corpo chega em JSON de texto.
        // Tentar primeiro o JSON directo: num texto que começa por "{\n", o zlib_decode não falha,
        // devolve lixo. Só depois tentar as descompressões, aceitando a primeira que dê JSON válido.
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            foreach (['zlib_decode', 'gzinflate', 'gzuncompress'] as $funcao) {
                $decoded = @$funcao($raw);
                if ($decoded === false) {
                    continue;
                }
                $payload = json_decode($decoded, true);
                if (is_array($payload)) {
                    break;
                }
            }
        }

        if (!is_array($payload)) {
            error_log('DahuaBridgeController: payload inválido (' . strlen($raw) . ' bytes).');
            return $response->withStatus(400);
        }

        // Picagens guardadas offline chegam em lote: {"Events":[{...},{...}]}
        $eventos = (isset($payload['Events']) && is_array($payload['Events'])) ? $payload['Events'] : [$payload];

        foreach ($eventos as $evento) {
            if (is_array($evento)) {
                $this->processarEvento($evento, $contexto);
            }
        }

        // Os relógios Dahua só dão o evento como entregue com {"Result":true};
        // com outra resposta reenviam o mesmo evento e a fila de picagens não avança.
        $response->getBody()->write(json_encode(['Result' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Processa um evento. $contexto = [PDO, relogio] quando o relógio veio do token;
     * null na rota antiga (resolve pelo S/N).
     */
    private function processarEvento(array $evento, ?array $contexto): void
    {
        if (($evento['Code'] ?? '') !== 'AccessControl') {
            return;
        }

        $data = $evento['Data'] ?? null;
        if (!is_array($data)) {
            return;
        }

        // Só aberturas com sucesso. Tentativas falhadas trazem Status 0 (e normalmente UserID vazio).
        if ((int) ($data['Status'] ?? 0) !== 1) {
            return;
        }

        $userId = trim((string) ($data['UserID'] ?? ''));
        if ($userId === '') {
            return;
        }

        // Defesa: eventos com dados biométricos nunca são processados nem registados.
        if (isset($data['FingerPrintData'])) {
            return;
        }

        $sn = (string) ($data['SN'] ?? '');

        if ($contexto === null) {
            [$db, $relogio] = $this->terminalService->resolverTenantPorSN($sn);
        } else {
            [$db, $relogio] = $contexto;
            if ($sn === '' || $sn !== (string) ($relogio['device_id'] ?? '')) {
                error_log('DahuaBridgeController: S/N do evento não corresponde ao relógio do token (relogio_id ' . ($relogio['id'] ?? '?') . ').');
                return;
            }
        }

        if (!$db || !$relogio) {
            return;
        }

        $tipo = ($data['Type'] ?? '') === 'Entry' ? 'entrada' : 'saida';

        if (isset($data['UTC'])) {
            $ts = (int) $data['UTC'];
        } elseif (isset($data['RealUTC'])) {
            $ts = (int) $data['RealUTC'];
        } else {
            $ts = time();
        }

        $registo = [
            'UserID'       => $userId,
            'Timestamp'    => date('Y-m-d H:i:s', $ts),
            'tipo_dahua'   => $tipo,
            'origem_dahua' => 'relogio',
        ];

        try {
            $this->terminalService->processarRegisto($db, $relogio, $registo);
        } catch (\Throwable $e) {
            error_log('DahuaBridgeController: picagem do funcionário ' . $userId . ' no relogio_id ' . ($relogio['id'] ?? '?') . ' não gravada: ' . $e->getMessage());
        }
    }
}
