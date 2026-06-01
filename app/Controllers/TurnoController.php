<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * TurnoController — CRUD de turnos (templates de horário)
 */
class TurnoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/turnos
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();
        $stmt = $db->query("SELECT * FROM turnos WHERE activo = 1 ORDER BY nome ASC");
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * POST /api/turnos
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();
        $body = $request->getParsedBody() ?? [];

        $erro = $this->validar($body);
        if ($erro) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => $erro]);
        }

        $calc = $this->calcular($body);

        $stmt = $db->prepare("
            INSERT INTO turnos (
                nome, tipo, hora_entrada, hora_saida,
                hora_inicio_intervalo, hora_fim_intervalo,
                atravessa_dia_civil, classificacao_legal, horas_efectivas
            ) VALUES (
                :nome, :tipo, :entrada, :saida,
                :ini_int, :fim_int,
                :atravessa, :legal, :horas
            )
        ");

        $stmt->execute([
            ':nome'      => $body['nome'],
            ':tipo'      => $body['tipo'],
            ':entrada'   => $body['hora_entrada'] ?? null,
            ':saida'     => $body['hora_saida'] ?? null,
            ':ini_int'   => $body['hora_inicio_intervalo'] ?? null,
            ':fim_int'   => $body['hora_fim_intervalo'] ?? null,
            ':atravessa' => $calc['atravessa'],
            ':legal'     => $calc['legal'],
            ':horas'     => $calc['horas']
        ]);

        return $this->json($response, 201, [
            'mensagem' => 'Turno criado com sucesso.',
            'id' => $db->lastInsertId()
        ]);
    }

    /**
     * PUT /api/turnos/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $db = $this->db();
        $body = $request->getParsedBody() ?? [];

        $erro = $this->validar($body);
        if ($erro) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => $erro]);
        }

        $calc = $this->calcular($body);

        $stmt = $db->prepare("
            UPDATE turnos
            SET nome = :nome, tipo = :tipo, hora_entrada = :entrada, hora_saida = :saida,
                hora_inicio_intervalo = :ini_int, hora_fim_intervalo = :fim_int,
                atravessa_dia_civil = :atravessa, classificacao_legal = :legal, horas_efectivas = :horas
            WHERE id = :id
        ");

        $stmt->execute([
            ':nome'      => $body['nome'],
            ':tipo'      => $body['tipo'],
            ':entrada'   => $body['hora_entrada'] ?? null,
            ':saida'     => $body['hora_saida'] ?? null,
            ':ini_int'   => $body['hora_inicio_intervalo'] ?? null,
            ':fim_int'   => $body['hora_fim_intervalo'] ?? null,
            ':atravessa' => $calc['atravessa'],
            ':legal'     => $calc['legal'],
            ':horas'     => $calc['horas'],
            ':id'        => $id
        ]);

        return $this->json($response, 200, ['mensagem' => 'Turno actualizado com sucesso.']);
    }

    /**
     * DELETE /api/turnos/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $db = $this->db();
        $db->prepare("UPDATE turnos SET activo = 0 WHERE id = :id")->execute([':id' => $id]);

        return $this->json($response, 200, ['mensagem' => 'Turno desactivado com sucesso.']);
    }

    private function validar(array $body): ?string
    {
        if (empty($body['nome'])) return 'O nome é obrigatório.';
        if (mb_strlen($body['nome']) > 80) return 'O nome não pode exceder 80 caracteres.';
        if (empty($body['tipo'])) return 'O tipo é obrigatório.';
        if ($body['tipo'] === 'trabalho') {
            if (empty($body['hora_entrada'])) return 'A hora de entrada é obrigatória para turnos de trabalho.';
            if (empty($body['hora_saida'])) return 'A hora de saída é obrigatória para turnos de trabalho.';
        }
        return null;
    }

    private function calcular(array $body): array
    {
        if ($body['tipo'] !== 'trabalho') {
            return ['atravessa' => 0, 'legal' => 'nao_aplicavel', 'horas' => 0];
        }

        $e = $body['hora_entrada'];
        $s = $body['hora_saida'];
        $atravessa = ($s < $e) ? 1 : 0;

        // Horas efectivas
        $ts_e = strtotime("2000-01-01 $e");
        $ts_s = strtotime("2000-01-01 $s");
        if ($atravessa) {
            $ts_s += 86400;
        }
        $segundos = $ts_s - $ts_e;

        if (!empty($body['hora_inicio_intervalo']) && !empty($body['hora_fim_intervalo'])) {
            $ts_ie = strtotime("2000-01-01 " . $body['hora_inicio_intervalo']);
            $ts_is = strtotime("2000-01-01 " . $body['hora_fim_intervalo']);

            if ($atravessa && $ts_ie < $ts_e) $ts_ie += 86400;
            if ($atravessa && $ts_is < $ts_e) $ts_is += 86400;
            if ($ts_is < $ts_ie) $ts_is += 86400; // Intervalo atravessa meia-noite

            $segundos -= ($ts_is - $ts_ie);
        }

        $horas_efectivas = round($segundos / 3600, 2);

        // Classificação legal (Nocturno >= 3h entre 20h e 06h)
        $minutosNocturnos = 0;
        $atual = $ts_e;
        while ($atual < $ts_s) {
            $h = (int) date('H', $atual);
            if ($h >= 20 || $h < 6) {
                $minutosNocturnos++;
            }
            $atual += 60;
        }

        $legal = ($minutosNocturnos >= 180) ? 'nocturno' : 'diurno';

        return [
            'atravessa' => $atravessa,
            'legal'     => $legal,
            'horas'     => $horas_efectivas
        ];
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
