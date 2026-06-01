<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * EscalaController — Gestão de padrões de escala e atribuições
 */
class EscalaController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/escalas
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();
        $user = $request->getAttribute('auth_user');
        $perfil = $request->getAttribute('auth_perfil');

        $where = ['e.activo = 1'];
        $bind = [];

        if ($perfil === 'supervisor' && !empty($user->funcionario_id)) {
            $where[] = "e.id IN (SELECT escala_id FROM funcionario_escala fe JOIN funcionarios f ON fe.funcionario_id = f.id WHERE f.supervisor_id = :sid OR f.id = :sid_self)";
            $bind[':sid'] = (int) $user->funcionario_id;
            $bind[':sid_self'] = (int) $user->funcionario_id;
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT e.*, d.nome AS departamento_nome
            FROM escalas e
            LEFT JOIN departamentos d ON e.departamento_id = d.id
            WHERE {$whereStr}
            ORDER BY e.nome ASC
        ");
        $stmt->execute($bind);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * POST /api/escalas
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();
        $body = $request->getParsedBody() ?? [];

        $erro = $this->validarEscala($body);
        if ($erro) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => $erro]);
        }

        $stmt = $db->prepare("
            INSERT INTO escalas (nome, descricao, departamento_id, tamanho_ciclo)
            VALUES (:nome, :descricao, :dep_id, :ciclo)
        ");

        $stmt->execute([
            ':nome'      => $body['nome'],
            ':descricao' => $body['descricao'] ?? null,
            ':dep_id'    => $body['departamento_id'] ? (int) $body['departamento_id'] : null,
            ':ciclo'     => (int) $body['tamanho_ciclo']
        ]);

        return $this->json($response, 201, [
            'mensagem' => 'Escala criada com sucesso.',
            'id' => $db->lastInsertId()
        ]);
    }

    /**
     * GET /api/escalas/{id}
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $db = $this->db();
        $user = $request->getAttribute('auth_user');
        $perfil = $request->getAttribute('auth_perfil');

        $stmt = $db->prepare("
            SELECT e.*, d.nome AS departamento_nome
            FROM escalas e
            LEFT JOIN departamentos d ON e.departamento_id = d.id
            WHERE e.id = :id AND e.activo = 1
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $escala = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$escala) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Escala não encontrada.']);
        }

        // Verificar permissão do supervisor
        if ($perfil === 'supervisor' && !empty($user->funcionario_id)) {
            $check = $db->prepare("
                SELECT 1 FROM funcionario_escala fe
                JOIN funcionarios f ON fe.funcionario_id = f.id
                WHERE fe.escala_id = :eid AND (f.supervisor_id = :sid OR f.id = :sid_self)
                LIMIT 1
            ");
            $check->execute([':eid' => $id, ':sid' => (int) $user->funcionario_id, ':sid_self' => (int) $user->funcionario_id]);
            if (!$check->fetch()) {
                return $this->json($response, 403, ['erro' => true, 'mensagem' => 'Não tem permissão para ver esta escala.']);
            }
        }

        // Turnos da escala
        $stmtTurnos = $db->prepare("
            SELECT et.posicao, t.*
            FROM escala_turnos et
            JOIN turnos t ON et.turno_id = t.id
            WHERE et.escala_id = :eid
            ORDER BY et.posicao ASC
        ");
        $stmtTurnos->execute([':eid' => $id]);
        $escala['turnos'] = $stmtTurnos->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $escala]);
    }

    /**
     * PUT /api/escalas/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $db = $this->db();
        $body = $request->getParsedBody() ?? [];

        $erro = $this->validarEscala($body);
        if ($erro) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => $erro]);
        }

        $stmt = $db->prepare("
            UPDATE escalas
            SET nome = :nome, descricao = :descricao, departamento_id = :dep_id, tamanho_ciclo = :ciclo
            WHERE id = :id
        ");

        $stmt->execute([
            ':nome'      => $body['nome'],
            ':descricao' => $body['descricao'] ?? null,
            ':dep_id'    => $body['departamento_id'] ? (int) $body['departamento_id'] : null,
            ':ciclo'     => (int) $body['tamanho_ciclo'],
            ':id'        => $id
        ]);

        return $this->json($response, 200, ['mensagem' => 'Escala actualizada com sucesso.']);
    }

    /**
     * DELETE /api/escalas/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $db = $this->db();
        $db->prepare("UPDATE escalas SET activo = 0 WHERE id = :id")->execute([':id' => $id]);

        return $this->json($response, 200, ['mensagem' => 'Escala desactivada com sucesso.']);
    }

    /**
     * POST /api/escalas/{id}/turnos
     */
    public function adicionarTurno(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $escalaId = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $db = $this->db();

        $posicao = (int) ($body['posicao'] ?? 0);
        $turnoId = (int) ($body['turno_id'] ?? 0);

        if ($posicao < 1 || $turnoId < 1) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Posição e turno_id são obrigatórios.']);
        }

        // Verificar se a posição não excede o ciclo
        $stmt = $db->prepare("SELECT tamanho_ciclo FROM escalas WHERE id = :id");
        $stmt->execute([':id' => $escalaId]);
        $ciclo = $stmt->fetchColumn();
        if ($posicao > $ciclo) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => "A posição {$posicao} excede o tamanho do ciclo desta escala ({$ciclo})."]);
        }

        $stmt = $db->prepare("
            INSERT INTO escala_turnos (escala_id, posicao, turno_id)
            VALUES (:eid, :pos, :tid)
            ON DUPLICATE KEY UPDATE turno_id = :tid2
        ");

        $stmt->execute([
            ':eid'  => $escalaId,
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
        $escalaId = (int) $args['id'];
        $posicao = (int) $args['posicao'];
        $db = $this->db();

        $db->prepare("DELETE FROM escala_turnos WHERE escala_id = :eid AND posicao = :pos")
           ->execute([':eid' => $escalaId, ':pos' => $posicao]);

        return $this->json($response, 200, ['mensagem' => 'Turno removido da escala.']);
    }

    /**
     * GET /api/escalas/{id}/atribuicoes
     */
    public function listarAtribuicoes(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $db = $this->db();

        $stmt = $db->prepare("
            SELECT fe.*, f.nome_completo AS funcionario_nome, f.numero_funcionario
            FROM funcionario_escala fe
            JOIN funcionarios f ON fe.funcionario_id = f.id
            WHERE fe.escala_id = :eid
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
        $escalaId = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $db = $this->db();

        $funcionarioId = (int) ($body['funcionario_id'] ?? 0);
        $dataInicio = $body['data_inicio'] ?? null;
        $posicaoInicial = (int) ($body['posicao_inicial'] ?? 1);

        if (!$funcionarioId || !$dataInicio) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'funcionario_id e data_inicio são obrigatórios.']);
        }

        // Verificar se a posição não excede o ciclo
        $stmt = $db->prepare("SELECT tamanho_ciclo FROM escalas WHERE id = :id");
        $stmt->execute([':id' => $escalaId]);
        $ciclo = $stmt->fetchColumn();
        if ($posicaoInicial > $ciclo) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => "A posição inicial {$posicaoInicial} excede o tamanho do ciclo desta escala ({$ciclo})."]);
        }

        // Fechar atribuição anterior
        $db->prepare("UPDATE funcionario_escala SET data_fim = DATE_SUB(:data, INTERVAL 1 DAY) WHERE funcionario_id = :fid AND data_fim IS NULL")
           ->execute([':fid' => $funcionarioId, ':data' => $dataInicio]);

        $stmt = $db->prepare("
            INSERT INTO funcionario_escala (funcionario_id, escala_id, data_inicio, data_fim, posicao_inicial)
            VALUES (:fid, :eid, :inicio, :fim, :pos)
        ");

        $stmt->execute([
            ':fid'    => $funcionarioId,
            ':eid'    => $escalaId,
            ':inicio' => $dataInicio,
            ':fim'    => $body['data_fim'] ?? null,
            ':pos'    => $posicaoInicial
        ]);

        return $this->json($response, 201, ['mensagem' => 'Funcionário atribuído à escala com sucesso.']);
    }

    /**
     * DELETE /api/escalas/{id}/atribuicoes/{funcionario_id}
     */
    public function removerAtribuicao(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $escalaId = (int) $args['id'];
        $funcionarioId = (int) $args['funcionario_id'];
        $db = $this->db();

        $db->prepare("DELETE FROM funcionario_escala WHERE escala_id = :eid AND funcionario_id = :fid")
           ->execute([':eid' => $escalaId, ':fid' => $funcionarioId]);

        return $this->json($response, 200, ['mensagem' => 'Atribuição removida com sucesso.']);
    }

    private function validarEscala(array $body): ?string
    {
        if (empty($body['nome'])) return 'O nome da escala é obrigatório.';
        if (mb_strlen($body['nome']) > 100) return 'O nome não pode exceder 100 caracteres.';
        if (empty($body['tamanho_ciclo']) || (int)$body['tamanho_ciclo'] < 1) return 'O tamanho do ciclo é obrigatório e deve ser pelo menos 1.';
        return null;
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
