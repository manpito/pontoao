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
 * MarcacaoFaltaController — Gestão de marcações em falta
 */
class MarcacaoFaltaController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/marcacoes-falta
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $user   = $request->getAttribute('auth_user');
        $perfil = $request->getAttribute('auth_perfil');
        $db     = $this->db();

        $where = [];
        $bind  = [];

        // RBAC: Supervisor vê apenas a sua equipa
        if ($perfil === 'supervisor' && !empty($user->funcionario_id)) {
            $where[] = '(f.supervisor_id = :sid OR f.id = :sid_self)';
            $bind[':sid'] = (int) $user->funcionario_id;
            $bind[':sid_self'] = (int) $user->funcionario_id;
        }

        if (!empty($params['estado'])) {
            $where[] = 'mf.estado = :estado';
            $bind[':estado'] = $params['estado'];
        }

        if (!empty($params['data_inicio'])) {
            $where[] = 'mf.data >= :data_inicio';
            $bind[':data_inicio'] = $params['data_inicio'];
        }

        if (!empty($params['data_fim'])) {
            $where[] = 'mf.data <= :data_fim';
            $bind[':data_fim'] = $params['data_fim'];
        }

        if (!empty($params['funcionario_id'])) {
            $where[] = 'mf.funcionario_id = :fid';
            $bind[':fid'] = (int) $params['funcionario_id'];
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("
            SELECT
                mf.*,
                f.nome_completo AS funcionario_nome,
                f.numero_funcionario AS funcionario_numero,
                m.data_hora AS data_hora_entrada
            FROM marcacoes_em_falta mf
            JOIN funcionarios f ON mf.funcionario_id = f.id
            LEFT JOIN marcacoes m ON mf.marcacao_entrada_id = m.id
            {$whereStr}
            ORDER BY mf.data DESC, f.nome_completo ASC
        ");
        $stmt->execute($bind);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json(200, ['dados' => $dados]);
    }

    /**
     * GET /api/marcacoes-falta/pendentes
     */
    public function pendentes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user   = $request->getAttribute('auth_user');
        $perfil = $request->getAttribute('auth_perfil');
        $db     = $this->db();

        $where = ["mf.estado = 'pendente'"];
        $bind  = [];

        if ($perfil === 'supervisor' && !empty($user->funcionario_id)) {
            $where[] = '(f.supervisor_id = :sid OR f.id = :sid_self)';
            $bind[':sid'] = (int) $user->funcionario_id;
            $bind[':sid_self'] = (int) $user->funcionario_id;
        }

        $whereStr = 'WHERE ' . implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM marcacoes_em_falta mf
            JOIN funcionarios f ON mf.funcionario_id = f.id
            {$whereStr}
        ");
        $stmt->execute($bind);
        $total = (int) $stmt->fetchColumn();

        return $this->json(200, ['total' => $total]);
    }

    /**
     * PUT /api/marcacoes-falta/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id     = (int) $args['id'];
        $body   = $request->getParsedBody() ?? [];
        $user   = $request->getAttribute('auth_user');
        $perfil = $request->getAttribute('auth_perfil');
        $userId = $request->getAttribute('auth_user_id');
        $db     = $this->db();

        $estado = $body['estado'] ?? '';
        $nota   = $body['nota_classificacao'] ?? null;
        $horaEntrada = $body['hora_entrada'] ?? null; // formato HH:MM
        $horaSaida   = $body['hora_saida']   ?? null; // formato HH:MM

        if (empty($estado)) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'O estado é obrigatório.']);
        }

        $estadosValidos = ['justificada_trabalho', 'justificada_motivo', 'justificada_outras', 'injustificada_meio_dia', 'injustificada_falta'];
        if (!in_array($estado, $estadosValidos)) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'Estado de classificação inválido.']);
        }

        // Verificar existência e RBAC
        $stmt = $db->prepare("
            SELECT mf.id
            FROM marcacoes_em_falta mf
            JOIN funcionarios f ON mf.funcionario_id = f.id
            WHERE mf.id = :id
        ");
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Marcação em falta não encontrada.']);
        }

        // Filtro supervisor para PUT
        if ($perfil === 'supervisor' && !empty($user->funcionario_id)) {
            $check = $db->prepare("
                SELECT mf.id
                FROM marcacoes_em_falta mf
                JOIN funcionarios f ON mf.funcionario_id = f.id
                WHERE mf.id = :id AND (f.supervisor_id = :sid OR f.id = :sid_self)
            ");
            $check->execute([':id' => $id, ':sid' => (int) $user->funcionario_id, ':sid_self' => (int) $user->funcionario_id]);
            if (!$check->fetch()) {
                return $this->json(403, ['erro' => true, 'mensagem' => 'Sem permissão para classificar esta marcação.']);
            }
        }

        $stmt = $db->prepare("
            UPDATE marcacoes_em_falta
            SET estado = :estado,
                nota_classificacao = :nota,
                classificado_por = :classificador,
                data_classificacao = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':estado'        => $estado,
            ':nota'          => $nota,
            ':classificador' => $userId,
            ':id'            => $id
        ]);

        // Criar marcações quando aplicável
        $stmtMf = $db->prepare("SELECT funcionario_id, data FROM marcacoes_em_falta WHERE id = :id");
        $stmtMf->execute([':id' => $id]);
        $mf = $stmtMf->fetch(PDO::FETCH_ASSOC);

        if (in_array($estado, ['justificada_trabalho', 'justificada_outras']) && !empty($horaEntrada)) {
            $dataHoraEntrada = $mf['data'] . ' ' . $horaEntrada . ':00';
            // verificar duplicado
            $dup = $db->prepare("
                SELECT id FROM marcacoes
                WHERE funcionario_id = :fid
                  AND tipo = 'entrada'
                  AND ABS(TIMESTAMPDIFF(SECOND, data_hora, :dh)) < 60
            ");
            $dup->execute([':fid' => $mf['funcionario_id'], ':dh' => $dataHoraEntrada]);
            if (!$dup->fetch()) {
                $db->prepare("
                    INSERT INTO marcacoes (funcionario_id, tipo, data_hora, data_hora_original, origem, editada_por)
                    VALUES (:fid, 'entrada', :dh, :dh, 'manual', :uid)
                ")->execute([':fid' => $mf['funcionario_id'], ':dh' => $dataHoraEntrada, ':uid' => $userId]);
            }
        }

        if (in_array($estado, ['justificada_trabalho', 'justificada_outras']) && !empty($horaSaida)) {
            $dataHoraSaida = $mf['data'] . ' ' . $horaSaida . ':00';
            // verificar duplicado
            $dup = $db->prepare("
                SELECT id FROM marcacoes
                WHERE funcionario_id = :fid
                  AND tipo = 'saida'
                  AND ABS(TIMESTAMPDIFF(SECOND, data_hora, :dh)) < 60
            ");
            $dup->execute([':fid' => $mf['funcionario_id'], ':dh' => $dataHoraSaida]);
            if (!$dup->fetch()) {
                $db->prepare("
                    INSERT INTO marcacoes (funcionario_id, tipo, data_hora, data_hora_original, origem, editada_por)
                    VALUES (:fid, 'saida', :dh, :dh, 'manual', :uid)
                ")->execute([':fid' => $mf['funcionario_id'], ':dh' => $dataHoraSaida, ':uid' => $userId]);
            }
        }

        return $this->json(200, ['mensagem' => 'Marcação classificada com sucesso.']);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
