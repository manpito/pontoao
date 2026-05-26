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
 * RotacaoController — Gestão de rotações e ciclos de trabalho
 *
 * Suporta:
 * - Ciclos on/off (ex: 28 dias on / 28 dias off para oil & gas)
 * - Turnos rotativos (manhã/tarde/noite)
 * - Personalizados (qualquer combinação de fases)
 * - Trabalho ao fim de semana e feriados (ignora_fds, ignora_feriados)
 * - Saída antecipada num dia específico da semana
 */
class RotacaoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/rotacoes
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db   = $this->db();
        $stmt = $db->query("
            SELECT r.*, h.nome AS horario_nome,
                   COUNT(DISTINCT fr.funcionario_id) AS funcionarios_afectos
            FROM rotacoes r
            JOIN horarios h ON r.horario_id = h.id
            LEFT JOIN funcionario_rotacao fr ON fr.rotacao_id = r.id AND fr.data_fim IS NULL
            GROUP BY r.id
            ORDER BY r.nome ASC
        ");

        $rotacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rotacoes as &$rot) {
            $fases = $db->prepare("SELECT * FROM rotacao_fases WHERE rotacao_id = :rid ORDER BY ordem ASC");
            $fases->execute([':rid' => $rot['id']]);
            $rot['fases'] = $fases->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->json(200, ['dados' => $rotacoes]);
    }

    /**
     * GET /api/rotacoes/{id}
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $db = $this->db();

        $stmt = $db->prepare("SELECT r.*, h.nome AS horario_nome FROM rotacoes r JOIN horarios h ON r.horario_id = h.id WHERE r.id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $rotacao = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rotacao) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Rotação não encontrada.']);
        }

        $fases = $db->prepare("SELECT * FROM rotacao_fases WHERE rotacao_id = :rid ORDER BY ordem ASC");
        $fases->execute([':rid' => $id]);
        $rotacao['fases'] = $fases->fetchAll(PDO::FETCH_ASSOC);

        // Funcionários afectos
        $funcs = $db->prepare("
            SELECT f.id, f.nome_completo, f.numero_funcionario, fr.data_inicio, fr.fase_inicial
            FROM funcionario_rotacao fr
            JOIN funcionarios f ON fr.funcionario_id = f.id
            WHERE fr.rotacao_id = :rid AND fr.data_fim IS NULL
        ");
        $funcs->execute([':rid' => $id]);
        $rotacao['funcionarios'] = $funcs->fetchAll(PDO::FETCH_ASSOC);

        return $this->json(200, ['dados' => $rotacao]);
    }

    /**
     * POST /api/rotacoes
     *
     * Exemplos de payload:
     *
     * Ciclo 28/28 oil & gas:
     * {
     *   "horario_id": 1,
     *   "nome": "Rotação 28/28",
     *   "tipo": "ciclo_on_off",
     *   "dias_on": 28, "dias_off": 28,
     *   "data_inicio_ciclo": "2026-01-01",
     *   "ignora_fds": true, "ignora_feriados": true,
     *   "fases": [
     *     {"ordem":1,"nome":"On","tipo_fase":"trabalho","duracao_dias":28,"hora_entrada":"07:00","hora_saida":"19:00"},
     *     {"ordem":2,"nome":"Off","tipo_fase":"folga","duracao_dias":28}
     *   ]
     * }
     *
     * Turno rotativo manhã/tarde/noite:
     * {
     *   "horario_id": 1,
     *   "nome": "Turno 3x8",
     *   "tipo": "turno_rotativo",
     *   "ignora_fds": true,
     *   "fases": [
     *     {"ordem":1,"nome":"Manhã","tipo_fase":"trabalho","duracao_dias":7,"hora_entrada":"06:00","hora_saida":"14:00"},
     *     {"ordem":2,"nome":"Tarde","tipo_fase":"trabalho","duracao_dias":7,"hora_entrada":"14:00","hora_saida":"22:00"},
     *     {"ordem":3,"nome":"Noite","tipo_fase":"trabalho","duracao_dias":7,"hora_entrada":"22:00","hora_saida":"06:00"}
     *   ]
     * }
     *
     * Semana normal com saída antecipada à sexta:
     * {
     *   "horario_id": 1,
     *   "nome": "Horário Normal + Sexta Curta",
     *   "tipo": "personalizado",
     *   "dia_saida_antecipada": 5,
     *   "hora_saida_antecipada": "15:00:00",
     *   "fases": [
     *     {"ordem":1,"nome":"Semana","tipo_fase":"trabalho","duracao_dias":5,"hora_entrada":"08:00","hora_saida":"17:00"}
     *   ]
     * }
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
            INSERT INTO rotacoes (
                horario_id, nome, tipo, dias_on, dias_off, data_inicio_ciclo,
                ignora_fds, ignora_feriados, dia_saida_antecipada, hora_saida_antecipada
            ) VALUES (
                :hid, :nome, :tipo, :dias_on, :dias_off, :data_ini,
                :fds, :fer, :dia_ant, :hora_ant
            )
        ");

        $stmt->execute([
            ':hid'      => (int) $body['horario_id'],
            ':nome'     => $body['nome'],
            ':tipo'     => $body['tipo'] ?? 'ciclo_on_off',
            ':dias_on'  => $body['dias_on'] ?? null,
            ':dias_off' => $body['dias_off'] ?? null,
            ':data_ini' => $body['data_inicio_ciclo'] ?? null,
            ':fds'      => (int) ($body['ignora_fds'] ?? 0),
            ':fer'      => (int) ($body['ignora_feriados'] ?? 0),
            ':dia_ant'  => $body['dia_saida_antecipada'] ?? null,
            ':hora_ant' => $body['hora_saida_antecipada'] ?? null,
        ]);

        $rotacaoId = (int) $db->lastInsertId();

        // Inserir fases
        if (!empty($body['fases']) && is_array($body['fases'])) {
            $stmtFase = $db->prepare("
                INSERT INTO rotacao_fases (
                    rotacao_id, ordem, nome, tipo_fase, duracao_dias,
                    hora_entrada, hora_saida, hora_inicio_intervalo, hora_fim_intervalo, horas_dia
                ) VALUES (
                    :rid, :ordem, :nome, :tipo, :dur,
                    :entrada, :saida, :ini_int, :fim_int, :horas
                )
            ");

            foreach ($body['fases'] as $fase) {
                $stmtFase->execute([
                    ':rid'     => $rotacaoId,
                    ':ordem'   => (int) ($fase['ordem'] ?? 1),
                    ':nome'    => $fase['nome'],
                    ':tipo'    => $fase['tipo_fase'] ?? 'trabalho',
                    ':dur'     => (int) ($fase['duracao_dias'] ?? 1),
                    ':entrada' => $fase['hora_entrada'] ?? null,
                    ':saida'   => $fase['hora_saida'] ?? null,
                    ':ini_int' => $fase['hora_inicio_intervalo'] ?? null,
                    ':fim_int' => $fase['hora_fim_intervalo'] ?? null,
                    ':horas'   => $fase['horas_dia'] ?? null,
                ]);
            }
        }

        return $this->json(201, [
            'mensagem'   => 'Rotação criada com sucesso.',
            'id'         => $rotacaoId,
        ]);
    }

    /**
     * PUT /api/rotacoes/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $db   = $this->db();

        $stmt = $db->prepare("SELECT id FROM rotacoes WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Rotação não encontrada.']);
        }

        $db->prepare("
            UPDATE rotacoes SET
                nome = :nome, tipo = :tipo, dias_on = :dias_on, dias_off = :dias_off,
                data_inicio_ciclo = :data_ini, ignora_fds = :fds, ignora_feriados = :fer,
                dia_saida_antecipada = :dia_ant, hora_saida_antecipada = :hora_ant,
                activo = :activo
            WHERE id = :id
        ")->execute([
            ':nome'     => $body['nome'],
            ':tipo'     => $body['tipo'] ?? 'ciclo_on_off',
            ':dias_on'  => $body['dias_on'] ?? null,
            ':dias_off' => $body['dias_off'] ?? null,
            ':data_ini' => $body['data_inicio_ciclo'] ?? null,
            ':fds'      => (int) ($body['ignora_fds'] ?? 0),
            ':fer'      => (int) ($body['ignora_feriados'] ?? 0),
            ':dia_ant'  => $body['dia_saida_antecipada'] ?? null,
            ':hora_ant' => $body['hora_saida_antecipada'] ?? null,
            ':activo'   => (int) ($body['activo'] ?? 1),
            ':id'       => $id,
        ]);

        // Substituir fases se fornecidas
        if (!empty($body['fases']) && is_array($body['fases'])) {
            $db->prepare("DELETE FROM rotacao_fases WHERE rotacao_id = :rid")->execute([':rid' => $id]);

            $stmtFase = $db->prepare("
                INSERT INTO rotacao_fases (
                    rotacao_id, ordem, nome, tipo_fase, duracao_dias,
                    hora_entrada, hora_saida, hora_inicio_intervalo, hora_fim_intervalo, horas_dia
                ) VALUES (:rid, :ordem, :nome, :tipo, :dur, :entrada, :saida, :ini_int, :fim_int, :horas)
            ");

            foreach ($body['fases'] as $fase) {
                $stmtFase->execute([
                    ':rid'     => $id,
                    ':ordem'   => (int) ($fase['ordem'] ?? 1),
                    ':nome'    => $fase['nome'],
                    ':tipo'    => $fase['tipo_fase'] ?? 'trabalho',
                    ':dur'     => (int) ($fase['duracao_dias'] ?? 1),
                    ':entrada' => $fase['hora_entrada'] ?? null,
                    ':saida'   => $fase['hora_saida'] ?? null,
                    ':ini_int' => $fase['hora_inicio_intervalo'] ?? null,
                    ':fim_int' => $fase['hora_fim_intervalo'] ?? null,
                    ':horas'   => $fase['horas_dia'] ?? null,
                ]);
            }
        }

        return $this->json(200, ['mensagem' => 'Rotação actualizada com sucesso.']);
    }

    /**
     * POST /api/rotacoes/{id}/atribuir
     * Atribui uma rotação a um funcionário
     */
    public function atribuir(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $rotacaoId = (int) $args['id'];
        $body      = $request->getParsedBody() ?? [];
        $db        = $this->db();

        if (empty($body['funcionario_id'])) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'funcionario_id é obrigatório.']);
        }

        // Fechar atribuição anterior
        $db->prepare("
            UPDATE funcionario_rotacao SET data_fim = CURDATE()
            WHERE funcionario_id = :fid AND data_fim IS NULL
        ")->execute([':fid' => (int) $body['funcionario_id']]);

        $db->prepare("
            INSERT INTO funcionario_rotacao (funcionario_id, rotacao_id, data_inicio, fase_inicial)
            VALUES (:fid, :rid, :data, :fase)
        ")->execute([
            ':fid'  => (int) $body['funcionario_id'],
            ':rid'  => $rotacaoId,
            ':data' => $body['data_inicio'] ?? date('Y-m-d'),
            ':fase' => (int) ($body['fase_inicial'] ?? 1),
        ]);

        return $this->json(200, ['mensagem' => 'Rotação atribuída com sucesso.']);
    }

    /**
     * GET /api/rotacoes/{id}/calendario?mes=2026-04
     * Devolve o calendário de trabalho para um mês dado o ciclo da rotação
     */
    public function calendario(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id     = (int) $args['id'];
        $params = $request->getQueryParams();
        $mes    = $params['mes'] ?? date('Y-m');
        $db     = $this->db();

        $stmt = $db->prepare("SELECT r.*, GROUP_CONCAT(rf.nome ORDER BY rf.ordem) as fases_nomes FROM rotacoes r LEFT JOIN rotacao_fases rf ON rf.rotacao_id = r.id WHERE r.id = :id GROUP BY r.id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $rotacao = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rotacao) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Rotação não encontrada.']);
        }

        $fases = $db->prepare("SELECT * FROM rotacao_fases WHERE rotacao_id = :rid ORDER BY ordem ASC");
        $fases->execute([':rid' => $id]);
        $fasesArr = $fases->fetchAll(PDO::FETCH_ASSOC);

        $calendario = $this->calcularCalendario($rotacao, $fasesArr, $mes . '-01', $mes . '-' . date('t', strtotime($mes . '-01')));

        return $this->json(200, ['rotacao' => $rotacao['nome'], 'mes' => $mes, 'dias' => $calendario]);
    }

    /**
     * Calcula o calendário de trabalho para um período dado o ciclo da rotação
     */
    public function calcularCalendario(array $rotacao, array $fases, string $inicio, string $fim): array
    {
        if (empty($fases) || !$rotacao['data_inicio_ciclo']) {
            return [];
        }

        $totalDiasCiclo = array_sum(array_column($fases, 'duracao_dias'));
        $refInicio      = strtotime($rotacao['data_inicio_ciclo']);
        $calendario     = [];

        $atual = strtotime($inicio);
        $fimTs = strtotime($fim);

        while ($atual <= $fimTs) {
            $dataStr   = date('Y-m-d', $atual);
            $diaSemana = (int) date('N', $atual);

            // Calcular posição no ciclo
            $diasDesdeRef = (int) floor(($atual - $refInicio) / 86400);
            if ($diasDesdeRef < 0) {
                $diasDesdeRef = $totalDiasCiclo - (abs($diasDesdeRef) % $totalDiasCiclo);
            }
            $posicaoNoCiclo = $diasDesdeRef % $totalDiasCiclo;

            // Determinar fase actual
            $faseActual = null;
            $acumulado  = 0;
            foreach ($fases as $fase) {
                $acumulado += (int) $fase['duracao_dias'];
                if ($posicaoNoCiclo < $acumulado) {
                    $faseActual = $fase;
                    break;
                }
            }

            $diaInfo = [
                'data'       => $dataStr,
                'dia_semana' => $diaSemana,
                'fase'       => $faseActual['nome'] ?? null,
                'tipo'       => $faseActual['tipo_fase'] ?? 'folga',
            ];

            // Aplicar saída antecipada se configurada
            if (
                $faseActual && $faseActual['tipo_fase'] === 'trabalho' &&
                $rotacao['dia_saida_antecipada'] == $diaSemana &&
                $rotacao['hora_saida_antecipada']
            ) {
                $diaInfo['hora_saida_override'] = $rotacao['hora_saida_antecipada'];
            }

            if ($faseActual && $faseActual['tipo_fase'] === 'trabalho') {
                $diaInfo['hora_entrada'] = $faseActual['hora_entrada'];
                $diaInfo['hora_saida']   = $diaInfo['hora_saida_override'] ?? $faseActual['hora_saida'];
                $diaInfo['horas_dia']    = $faseActual['horas_dia'];
            }

            $calendario[] = $diaInfo;
            $atual = strtotime('+1 day', $atual);
        }

        return $calendario;
    }

    private function validar(array $body): ?string
    {
        if (empty($body['nome']))      return 'O campo nome é obrigatório.';
        if (empty($body['horario_id'])) return 'O campo horario_id é obrigatório.';

        if (($body['tipo'] ?? '') === 'ciclo_on_off') {
            if (empty($body['dias_on']) || empty($body['dias_off'])) {
                return 'dias_on e dias_off são obrigatórios para rotações de ciclo.';
            }
            if (empty($body['data_inicio_ciclo'])) {
                return 'data_inicio_ciclo é obrigatória para rotações de ciclo.';
            }
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
