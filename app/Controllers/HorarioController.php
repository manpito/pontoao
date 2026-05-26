<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * HorarioController — Gestão de horários de trabalho
 * Conforme LGT Art.º 96.º (8h/dia, 44h/semana) e Art.º 100.º (intervalo mín. 30min)
 */
class HorarioController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/horarios
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db   = $this->db();
        $stmt = $db->query("
            SELECT
                h.id, h.nome, h.tipo, h.horas_dia, h.horas_semana,
                h.tolerancia_entrada_min, h.intervalo_min, h.activo, h.criado_em,
                COUNT(fh.id) AS funcionarios_afectos
            FROM horarios h
            LEFT JOIN funcionario_horario fh ON fh.horario_id = h.id AND fh.data_fim IS NULL
            GROUP BY h.id
            ORDER BY h.nome ASC
        ");

        $horarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Carregar turnos para cada horário
        foreach ($horarios as &$h) {
            $t = $db->prepare("SELECT * FROM horario_turnos WHERE horario_id = :hid ORDER BY dia_semana ASC");
            $t->execute([':hid' => $h['id']]);
            $h['turnos'] = $t->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->json(200, ['dados' => $horarios]);
    }

    /**
     * POST /api/horarios
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];

        $erro = $this->validar($body);
        if ($erro) {
            return $this->json(422, ['erro' => true, 'mensagem' => $erro]);
        }

        $db = $this->db();

        $stmt = $db->prepare("
            INSERT INTO horarios (nome, tipo, horas_dia, horas_semana, tolerancia_entrada_min, intervalo_min)
            VALUES (:nome, :tipo, :horas_dia, :horas_semana, :tolerancia, :intervalo)
        ");
        $stmt->execute([
            ':nome'       => $body['nome'],
            ':tipo'       => $body['tipo'] ?? 'normal',
            ':horas_dia'  => (float) ($body['horas_dia'] ?? 8),
            ':horas_semana' => (float) ($body['horas_semana'] ?? 44),
            ':tolerancia' => (int) ($body['tolerancia_entrada_min'] ?? 10),
            ':intervalo'  => (int) ($body['intervalo_min'] ?? 60),
        ]);

        $horarioId = (int) $db->lastInsertId();

        // Inserir turnos se fornecidos
        if (!empty($body['turnos']) && is_array($body['turnos'])) {
            $stmtTurno = $db->prepare("
                INSERT INTO horario_turnos (horario_id, dia_semana, hora_entrada, hora_saida, hora_inicio_intervalo, hora_fim_intervalo, dia_folga)
                VALUES (:hid, :dia, :entrada, :saida, :ini_int, :fim_int, :folga)
            ");

            foreach ($body['turnos'] as $turno) {
                // Validar intervalo mínimo de 30min (Art.º 100.º LGT)
                if (empty($turno['dia_folga']) || !$turno['dia_folga']) {
                    if (!empty($turno['hora_inicio_intervalo']) && !empty($turno['hora_fim_intervalo'])) {
                        $ini = strtotime($turno['hora_inicio_intervalo']);
                        $fim = strtotime($turno['hora_fim_intervalo']);
                        if (($fim - $ini) < 1800) {
                            return $this->json(422, [
                                'erro'     => true,
                                'mensagem' => 'O intervalo mínimo obrigatório é de 30 minutos (Art.º 100.º LGT).',
                            ]);
                        }
                    }
                }

                $stmtTurno->execute([
                    ':hid'     => $horarioId,
                    ':dia'     => (int) $turno['dia_semana'],
                    ':entrada' => $turno['hora_entrada'] ?? '08:00:00',
                    ':saida'   => $turno['hora_saida'] ?? '17:00:00',
                    ':ini_int' => $turno['hora_inicio_intervalo'] ?? null,
                    ':fim_int' => $turno['hora_fim_intervalo'] ?? null,
                    ':folga'   => (int) ($turno['dia_folga'] ?? 0),
                ]);
            }
        }

        return $this->json(201, [
            'mensagem' => 'Horário criado com sucesso.',
            'id'       => $horarioId,
        ]);
    }

    /**
     * PUT /api/horarios/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $db   = $this->db();

        $stmt = $db->prepare("SELECT id FROM horarios WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Horário não encontrado.']);
        }

        $db->prepare("
            UPDATE horarios
            SET nome = :nome, tipo = :tipo, horas_dia = :horas_dia, horas_semana = :horas_semana,
                tolerancia_entrada_min = :tolerancia, intervalo_min = :intervalo, activo = :activo
            WHERE id = :id
        ")->execute([
            ':nome'       => $body['nome'],
            ':tipo'       => $body['tipo'] ?? 'normal',
            ':horas_dia'  => (float) ($body['horas_dia'] ?? 8),
            ':horas_semana' => (float) ($body['horas_semana'] ?? 44),
            ':tolerancia' => (int) ($body['tolerancia_entrada_min'] ?? 10),
            ':intervalo'  => (int) ($body['intervalo_min'] ?? 60),
            ':activo'     => isset($body['activo']) ? (int) $body['activo'] : 1,
            ':id'         => $id,
        ]);

        // Substituir turnos se fornecidos
        if (!empty($body['turnos']) && is_array($body['turnos'])) {
            $db->prepare("DELETE FROM horario_turnos WHERE horario_id = :hid")->execute([':hid' => $id]);

            $stmtTurno = $db->prepare("
                INSERT INTO horario_turnos (horario_id, dia_semana, hora_entrada, hora_saida, hora_inicio_intervalo, hora_fim_intervalo, dia_folga)
                VALUES (:hid, :dia, :entrada, :saida, :ini_int, :fim_int, :folga)
            ");

            foreach ($body['turnos'] as $turno) {
                $stmtTurno->execute([
                    ':hid'     => $id,
                    ':dia'     => (int) $turno['dia_semana'],
                    ':entrada' => $turno['hora_entrada'] ?? '08:00:00',
                    ':saida'   => $turno['hora_saida'] ?? '17:00:00',
                    ':ini_int' => $turno['hora_inicio_intervalo'] ?? null,
                    ':fim_int' => $turno['hora_fim_intervalo'] ?? null,
                    ':folga'   => (int) ($turno['dia_folga'] ?? 0),
                ]);
            }
        }

        return $this->json(200, ['mensagem' => 'Horário actualizado com sucesso.']);
    }

    private function validar(array $body): ?string
    {
        if (empty($body['nome'])) {
            return 'O campo nome é obrigatório.';
        }
        $hd = (float) ($body['horas_dia'] ?? 8);
        $hs = (float) ($body['horas_semana'] ?? 44);
        // Art.º 96.º LGT: máximo 8h/dia e 44h/semana
        if ($hd > 8) {
            return 'O limite legal é de 8 horas por dia (Art.º 96.º LGT).';
        }
        if ($hs > 44) {
            return 'O limite legal é de 44 horas por semana (Art.º 96.º LGT).';
        }
        return null;
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
