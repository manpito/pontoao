<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * TurnoController — Gestão de templates de turnos
 */
class TurnoController
{
    /**
     * GET /api/turnos
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->query("SELECT * FROM turnos WHERE activo = 1 ORDER BY nome ASC");
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * POST /api/turnos
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub  = TenantResolver::resolve();
        $db   = Database::tenant($sub);
        $body = $request->getParsedBody();

        $nome = trim($body['nome'] ?? '');
        if (empty($nome)) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'O nome do turno é obrigatório.']);
        }

        $calc = $this->calcularCamposTurno($body);

        try {
            $stmt = $db->prepare("
                INSERT INTO turnos (
                    nome, tipo, hora_entrada, hora_saida,
                    hora_inicio_intervalo, hora_fim_intervalo,
                    horas_efectivas, atravessa_dia_civil, classificacao_legal
                ) VALUES (
                    :nome, :tipo, :entrada, :saida,
                    :ini_int, :fim_int,
                    :horas, :atravessa, :classificacao
                )
            ");

            $stmt->execute([
                ':nome'          => $nome,
                ':tipo'          => $body['tipo'] ?? 'trabalho',
                ':entrada'       => $calc['hora_entrada'],
                ':saida'         => $calc['hora_saida'],
                ':ini_int'       => $calc['hora_inicio_intervalo'],
                ':fim_int'       => $calc['hora_fim_intervalo'],
                ':horas'         => $calc['horas_efectivas'],
                ':atravessa'     => $calc['atravessa_dia_civil'],
                ':classificacao' => $calc['classificacao_legal']
            ]);

            return $this->json($response, 201, ['mensagem' => 'Turno criado com sucesso.', 'id' => $db->lastInsertId()]);
        } catch (\PDOException $e) {
            return $this->json($response, 500, ['erro' => true, 'mensagem' => 'Erro ao criar turno: ' . $e->getMessage()]);
        }
    }

    /**
     * PUT /api/turnos/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $sub  = TenantResolver::resolve();
        $db   = Database::tenant($sub);
        $body = $request->getParsedBody();

        $nome = trim($body['nome'] ?? '');
        if (empty($nome)) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'O nome do turno é obrigatório.']);
        }

        $calc = $this->calcularCamposTurno($body);

        try {
            $stmt = $db->prepare("
                UPDATE turnos SET
                    nome = :nome, tipo = :tipo, hora_entrada = :entrada, hora_saida = :saida,
                    hora_inicio_intervalo = :ini_int, hora_fim_intervalo = :fim_int,
                    horas_efectivas = :horas, atravessa_dia_civil = :atravessa,
                    classificacao_legal = :classificacao
                WHERE id = :id
            ");

            $stmt->execute([
                ':nome'          => $nome,
                ':tipo'          => $body['tipo'] ?? 'trabalho',
                ':entrada'       => $calc['hora_entrada'],
                ':saida'         => $calc['hora_saida'],
                ':ini_int'       => $calc['hora_inicio_intervalo'],
                ':fim_int'       => $calc['hora_fim_intervalo'],
                ':horas'         => $calc['horas_efectivas'],
                ':atravessa'     => $calc['atravessa_dia_civil'],
                ':classificacao' => $calc['classificacao_legal'],
                ':id'            => $id
            ]);

            return $this->json($response, 200, ['mensagem' => 'Turno actualizado com sucesso.']);
        } catch (\PDOException $e) {
            return $this->json($response, 500, ['erro' => true, 'mensagem' => 'Erro ao actualizar turno: ' . $e->getMessage()]);
        }
    }

    /**
     * DELETE /api/turnos/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $db->prepare("UPDATE turnos SET activo = 0 WHERE id = :id")->execute([':id' => $id]);

        return $this->json($response, 200, ['mensagem' => 'Turno desactivado com sucesso.']);
    }

    private function calcularCamposTurno(array $body): array
    {
        $tipo = $body['tipo'] ?? 'trabalho';
        if ($tipo !== 'trabalho') {
            return [
                'hora_entrada' => null,
                'hora_saida' => null,
                'hora_inicio_intervalo' => null,
                'hora_fim_intervalo' => null,
                'horas_efectivas' => 0,
                'atravessa_dia_civil' => 0,
                'classificacao_legal' => 'nao_aplicavel'
            ];
        }

        $entrada = $body['hora_entrada'] ?? '00:00';
        $saida   = $body['hora_saida']   ?? '00:00';
        $iniInt  = $body['hora_inicio_intervalo'] ?? null;
        $fimInt  = $body['hora_fim_intervalo']    ?? null;

        $tEntrada = $this->toHours($entrada);
        $tSaida   = $this->toHours($saida);

        $atravessa = ($tSaida < $tEntrada) ? 1 : 0;
        $tSaidaCalculo = $atravessa ? $tSaida + 24 : $tSaida;

        $duracaoBruta = $tSaidaCalculo - $tEntrada;
        $duracaoIntervalo = 0;

        if ($iniInt && $fimInt) {
            $tIniInt = $this->toHours($iniInt);
            $tFimInt = $this->toHours($fimInt);
            if ($tFimInt < $tIniInt) $tFimInt += 24;
            $duracaoIntervalo = $tFimInt - $tIniInt;
        }

        $horasEfectivas = max(0, $duracaoBruta - $duracaoIntervalo);

        // Classificação legal (Nocturno se 3h+ entre 20h-06h)
        $nightHours = $this->overlap($tEntrada, $tSaidaCalculo, 0, 6) +
                      $this->overlap($tEntrada, $tSaidaCalculo, 20, 30) +
                      $this->overlap($tEntrada, $tSaidaCalculo, 44, 54);

        $classificacao = ($nightHours >= 3) ? 'nocturno' : 'diurno';

        return [
            'hora_entrada' => $entrada,
            'hora_saida' => $saida,
            'hora_inicio_intervalo' => $iniInt ?: null,
            'hora_fim_intervalo' => $fimInt ?: null,
            'horas_efectivas' => $horasEfectivas,
            'atravessa_dia_civil' => $atravessa,
            'classificacao_legal' => $classificacao
        ];
    }

    private function toHours(string $time): float
    {
        $parts = explode(':', $time);
        return (int)$parts[0] + ((int)($parts[1] ?? 0) / 60);
    }

    private function overlap(float $a, float $b, float $c, float $d): float
    {
        return max(0, min($b, $d) - max($a, $c));
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
