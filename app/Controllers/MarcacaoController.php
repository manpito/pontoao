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
 * MarcacaoController — Registo de marcações de ponto
 * Conforme LGT Art.º 96.º (limites diários) — registo imutável auditável MAPTSS
 */
class MarcacaoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/marcacoes
     * Filtros: ?funcionario_id=1&data_inicio=2026-01-01&data_fim=2026-01-31
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $user   = $request->getAttribute('auth_user');
        $where  = [];
        $bind   = [];

        // Funcionários sem perfil de gestão só vêem as suas próprias marcações
        // Filtro supervisor: apenas a sua equipa
        if ($user && $user->perfil === 'supervisor' && !empty($user->funcionario_id)) {
            $where[] = '(f.supervisor_id = :sid OR f.id = :sid)';
            $bind[':sid'] = (int) $user->funcionario_id;
        } else
        if ($user && in_array($user->perfil, ['funcionario'])) {
            $where[]            = 'f.id = :func_id';
            $bind[':func_id']   = (int) $user->sub;
        } elseif (!empty($params['funcionario_id'])) {
            $where[]            = 'm.funcionario_id = :func_id';
            $bind[':func_id']   = (int) $params['funcionario_id'];
        }

        if (!empty($params['data_inicio'])) {
            $where[]               = 'DATE(m.data_hora) >= :data_ini';
            $bind[':data_ini']     = $params['data_inicio'];
        }

        if (!empty($params['data_fim'])) {
            $where[]               = 'DATE(m.data_hora) <= :data_fim';
            $bind[':data_fim']     = $params['data_fim'];
        }

        if (!empty($params['numero'])) {
            $where[]               = 'f.numero_funcionario LIKE :numero';
            $bind[':numero']       = '%' . $params['numero'] . '%';
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db()->prepare("
            SELECT
                m.id, m.tipo, m.data_hora, m.origem, m.editada,
                m.motivo_edicao, m.bloqueada, m.criado_em,
                m.latitude, m.longitude, m.dentro_geofence,
                f.nome_completo AS funcionario, f.numero_funcionario
            FROM marcacoes m
            JOIN funcionarios f ON m.funcionario_id = f.id
            {$whereStr}
            ORDER BY m.data_hora DESC
            LIMIT 500
        ");
        $stmt->execute($bind);

        return $this->json(200, ['dados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * POST /api/marcacoes
     * Regista uma marcação manual ou web
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $user = $request->getAttribute('auth_user');
        $db   = $this->db();

        $funcionarioId = (int) ($body['funcionario_id'] ?? ($user ? $user->sub : 0));
        $tipo          = $body['tipo'] ?? '';
        $dataHora      = $body['data_hora'] ?? date('Y-m-d H:i:s');
        $origem        = $body['origem'] ?? 'manual';

        if (!$funcionarioId || !$tipo) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'funcionario_id e tipo são obrigatórios.']);
        }

        $tiposValidos = ['entrada', 'saida', 'inicio_intervalo', 'fim_intervalo', 'saida_servico', 'regresso_servico'];
        if (!in_array($tipo, $tiposValidos)) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Tipo de marcação inválido.']);
        }

        // Verificar se o período está fechado
        [$ano, $mes] = explode('-', substr($dataHora, 0, 7));
        $periodo = $db->prepare("SELECT estado FROM periodos_mensais WHERE ano = :ano AND mes = :mes LIMIT 1");
        $periodo->execute([':ano' => $ano, ':mes' => (int) $mes]);
        $p = $periodo->fetch(PDO::FETCH_ASSOC);
        if ($p && $p['estado'] === 'fechado') {
            return $this->json(409, ['erro' => true, 'mensagem' => 'O período mensal está fechado. Não é possível registar marcações.']);
        }

        $stmt = $db->prepare("
            INSERT INTO marcacoes (
                funcionario_id, tipo, data_hora, data_hora_original, origem,
                ip_marcacao, user_agent, latitude, longitude, dentro_geofence
            ) VALUES (
                :fid, :tipo, :data_hora, :data_hora_orig, :origem,
                :ip, :ua, :lat, :lng, :geofence
            )
        ");
        $stmt->execute([
            ':fid'           => $funcionarioId,
            ':tipo'          => $tipo,
            ':data_hora'     => $dataHora,
            ':data_hora_orig' => $dataHora,
            ':origem'        => $origem,
            ':ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'       => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ':lat'      => $body['latitude'] ?? null,
            ':lng'      => $body['longitude'] ?? null,
            ':geofence' => isset($body['dentro_geofence']) ? (int) $body['dentro_geofence'] : null,
        ]);

        $id = (int) $db->lastInsertId();

        // Log de auditoria
        $db->prepare("
            INSERT INTO log_auditoria (utilizador_id, accao, entidade, entidade_id, dados_depois, ip)
            VALUES (:uid, 'marcacao.criada', 'marcacao', :eid, :dados, :ip)
        ")->execute([
            ':uid'   => $user ? (int) $user->sub : null,
            ':eid'   => $id,
            ':dados' => json_encode(['tipo' => $tipo, 'data_hora' => $dataHora, 'origem' => $origem]),
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        return $this->json(201, [
            'mensagem' => 'Marcação registada com sucesso.',
            'id'       => $id,
        ]);
    }

    /**
     * PUT /api/marcacoes/{id}
     * Edição de marcação — só permitida se período não estiver fechado
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $user = $request->getAttribute('auth_user');
        $db   = $this->db();

        $stmt = $db->prepare("SELECT * FROM marcacoes WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $marcacao = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$marcacao) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Marcação não encontrada.']);
        }

        if ($marcacao['bloqueada']) {
            return $this->json(409, ['erro' => true, 'mensagem' => 'Marcação bloqueada — período mensal fechado. Não é possível editar.']);
        }

        if (empty($body['motivo_edicao'])) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'O campo motivo_edicao é obrigatório para editar uma marcação.']);
        }

        $db->prepare("
            UPDATE marcacoes
            SET data_hora = :data_hora, editada = 1,
                editada_por = :editada_por, motivo_edicao = :motivo, data_edicao = NOW()
            WHERE id = :id
        ")->execute([
            ':data_hora'   => $body['data_hora'] ?? $marcacao['data_hora'],
            ':editada_por' => $user ? (int) $user->sub : null,
            ':motivo'      => $body['motivo_edicao'],
            ':id'          => $id,
        ]);

        $db->prepare("
            INSERT INTO log_auditoria (utilizador_id, accao, entidade, entidade_id, dados_antes, dados_depois, ip)
            VALUES (:uid, 'marcacao.editada', 'marcacao', :eid, :antes, :depois, :ip)
        ")->execute([
            ':uid'   => $user ? (int) $user->sub : null,
            ':eid'   => $id,
            ':antes' => json_encode(['data_hora' => $marcacao['data_hora']]),
            ':depois' => json_encode(['data_hora' => $body['data_hora'], 'motivo' => $body['motivo_edicao']]),
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        return $this->json(200, ['mensagem' => 'Marcação actualizada com sucesso.']);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
