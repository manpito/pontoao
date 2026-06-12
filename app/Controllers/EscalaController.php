<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * EscalaController — Gestão de escalas, turnos em escala e atribuições
 */
class EscalaController
{
    /**
     * GET /api/escalas
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);
        $user = $request->getAttribute('user'); // Assumindo que o middleware coloca o user aqui

        $sql = "SELECT e.*, d.nome AS departamento_nome
                FROM escalas e
                LEFT JOIN departamentos d ON e.departamento_id = d.id
                WHERE e.activo = 1";

        $params = [];

        if (($user['perfil'] ?? '') === 'supervisor') {
            // Supervisors only see scales where their team members are assigned
            $sql = "SELECT DISTINCT e.*, d.nome AS departamento_nome
                    FROM escalas e
                    LEFT JOIN departamentos d ON e.departamento_id = d.id
                    JOIN funcionario_escala fe ON fe.escala_id = e.id
                    JOIN funcionarios f ON fe.funcionario_id = f.id
                    WHERE e.activo = 1 AND (f.supervisor_id = :sid OR f.id = :sid)";
            $params[':sid'] = $user['funcionario_id'];
        }

        $stmt = $db->prepare($sql . " ORDER BY e.nome ASC");
        $stmt->execute($params);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * POST /api/escalas
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub  = TenantResolver::resolve();
        $db   = Database::tenant($sub);
        $body = $request->getParsedBody();

        $nome = trim($body['nome'] ?? '');
        $ciclo = (int)($body['tamanho_ciclo'] ?? 0);

        if (empty($nome) || $ciclo <= 0) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Nome e tamanho do ciclo são obrigatórios.']);
        }

        $stmt = $db->prepare("
            INSERT INTO escalas (nome, descricao, departamento_id, tamanho_ciclo, regime)
            VALUES (:nome, :descricao, :dep_id, :ciclo, :regime)
        ");

        $stmt->execute([
            ':nome'      => $nome,
            ':descricao' => $body['descricao'] ?? null,
            ':dep_id'    => $body['departamento_id'] ?: null,
            ':ciclo'     => $ciclo,
            ':regime'    => in_array($body['regime'] ?? '', ['normal','turnos']) ? $body['regime'] : 'normal',
        ]);

        return $this->json($response, 201, ['mensagem' => 'Escala criada com sucesso.', 'id' => $db->lastInsertId()]);
    }

    /**
     * GET /api/escalas/{id}
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->prepare("SELECT e.*, d.nome AS departamento_nome FROM escalas e LEFT JOIN departamentos d ON e.departamento_id = d.id WHERE e.id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $escala = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$escala) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Escala não encontrada.']);
        }

        // Carregar turnos da escala
        $stmt = $db->prepare("
            SELECT et.posicao, t.*
            FROM escala_turnos et
            JOIN turnos t ON et.turno_id = t.id
            WHERE et.escala_id = :id
            ORDER BY et.posicao ASC
        ");
        $stmt->execute([':id' => $id]);
        $escala['turnos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $escala]);
    }

    /**
     * PUT /api/escalas/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $sub  = TenantResolver::resolve();
        $db   = Database::tenant($sub);
        $body = $request->getParsedBody();

        $nome = trim($body['nome'] ?? '');
        $ciclo = (int)($body['tamanho_ciclo'] ?? 0);

        if (empty($nome) || $ciclo <= 0) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Nome e tamanho do ciclo são obrigatórios.']);
        }

        $stmt = $db->prepare("
            UPDATE escalas SET
                nome = :nome, descricao = :descricao, departamento_id = :dep_id, tamanho_ciclo = :ciclo, regime = :regime
            WHERE id = :id
        ");

        $stmt->execute([
            ':nome'      => $nome,
            ':descricao' => $body['descricao'] ?? null,
            ':dep_id'    => $body['departamento_id'] ?: null,
            ':ciclo'     => $ciclo,
            ':regime'    => in_array($body['regime'] ?? '', ['normal','turnos']) ? $body['regime'] : 'normal',
            ':id'        => $id
        ]);

        return $this->json($response, 200, ['mensagem' => 'Escala actualizada com sucesso.']);
    }

    /**
     * DELETE /api/escalas/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $db->prepare("UPDATE escalas SET activo = 0 WHERE id = :id")->execute([':id' => $id]);

        return $this->json($response, 200, ['mensagem' => 'Escala desactivada com sucesso.']);
    }

    /**
     * POST /api/escalas/{id}/turnos
     */
    public function adicionarTurno(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $sub  = TenantResolver::resolve();
        $db   = Database::tenant($sub);
        $body = $request->getParsedBody();

        $turnoId = (int)($body['turno_id'] ?? 0);
        $posicao = (int)($body['posicao'] ?? 0);

        if (!$turnoId || !$posicao) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Turno e posição são obrigatórios.']);
        }

        // Verificar se posição é válida para o ciclo
        $stmt = $db->prepare("SELECT tamanho_ciclo FROM escalas WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $ciclo = (int)$stmt->fetchColumn();

        if ($posicao < 1 || $posicao > $ciclo) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => "Posição inválida. Deve estar entre 1 e $ciclo."]);
        }

        $stmt = $db->prepare("
            INSERT INTO escala_turnos (escala_id, posicao, turno_id)
            VALUES (:eid, :pos, :tid)
            ON DUPLICATE KEY UPDATE turno_id = :tid2
        ");

        $stmt->execute([
            ':eid'  => $id,
            ':pos'  => $posicao,
            ':tid'  => $turnoId,
            ':tid2' => $turnoId
        ]);

        return $this->json($response, 200, ['mensagem' => 'Turno adicionado à escala.']);
    }

    /**
     * DELETE /api/escalas/{id}/turnos/{posicao}
     */
    public function removerTurno(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $pos = (int) $args['posicao'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $db->prepare("DELETE FROM escala_turnos WHERE escala_id = :eid AND posicao = :pos")
           ->execute([':eid' => $id, ':pos' => $pos]);

        return $this->json($response, 200, ['mensagem' => 'Turno removido da escala.']);
    }

    /**
     * GET /api/escalas/{id}/atribuicoes
     */
    public function listarAtribuicoes(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->prepare("
            SELECT fe.*, f.nome_completo, f.numero_funcionario
            FROM funcionario_escala fe
            JOIN funcionarios f ON fe.funcionario_id = f.id
            WHERE fe.escala_id = :eid AND (fe.data_fim IS NULL OR fe.data_fim >= CURDATE())
            ORDER BY fe.data_inicio DESC
        ");
        $stmt->execute([':eid' => $id]);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * POST /api/escalas/{id}/atribuicoes
     */
    public function atribuir(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $sub  = TenantResolver::resolve();
        $db   = Database::tenant($sub);
        $body = $request->getParsedBody();

        $funcId  = (int)($body['funcionario_id'] ?? 0);
        $inicio  = $body['data_inicio'] ?? null;
        $posicao = (int)($body['posicao_inicial'] ?? 1);

        if (!$funcId || !$inicio) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Funcionário e data de início são obrigatórios.']);
        }

        // Fechar atribuição anterior se existir
        $db->prepare("UPDATE funcionario_escala SET data_fim = :ontem WHERE funcionario_id = :fid AND data_fim IS NULL")
           ->execute([':fid' => $funcId, ':ontem' => date('Y-m-d', strtotime('-1 day', strtotime($inicio)))]);

        $stmt = $db->prepare("
            INSERT INTO funcionario_escala (funcionario_id, escala_id, data_inicio, data_fim, posicao_inicial)
            VALUES (:fid, :eid, :inicio, :fim, :pos)
        ");

        $stmt->execute([
            ':fid'    => $funcId,
            ':eid'    => $id,
            ':inicio' => $inicio,
            ':fim'    => $body['data_fim'] ?: null,
            ':pos'    => $posicao
        ]);

        return $this->json($response, 201, ['mensagem' => 'Funcionário atribuído com sucesso.']);
    }

    /**
     * DELETE /api/escalas/{id}/atribuicoes/{funcionario_id}
     */
    public function removerAtribuicao(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $atribuicaoId = (int) $args['atribuicao_id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $db->prepare("UPDATE funcionario_escala SET data_fim = CURDATE() WHERE id = :aid AND data_fim IS NULL")
           ->execute([':aid' => $atribuicaoId]);

        return $this->json($response, 200, ['mensagem' => 'Atribuição encerrada.']);
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
