<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class TipoJustificacaoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/tipos-justificacao
     */
    public function listar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $apenasActivos = isset($queryParams['activo']) && $queryParams['activo'] == '1';

        $db = $this->db();

        $hasCor = true;
        try {
            $db->query("SELECT cor FROM tipos_justificacao LIMIT 1");
        } catch (\PDOException $e) {
            $hasCor = false;
        }

        $colCor = $hasCor ? ", cor" : "";
        $sql = "SELECT id, codigo, nome, comportamento{$colCor}, activo FROM tipos_justificacao";
        if ($apenasActivos) {
            $sql .= " WHERE activo = 1";
        }
        $sql .= " ORDER BY nome ASC";

        $stmt = $db->query($sql);

        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$hasCor) {
            foreach ($dados as &$d) {
                $d['cor'] = null;
            }
        }

        return $this->json(200, ['dados' => $dados]);
    }

    /**
     * POST /api/tipos-justificacao
     */
    public function criar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];

        if (empty($body['codigo'])) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'O campo código é obrigatório.']);
        }

        if (empty($body['nome'])) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'O campo nome é obrigatório.']);
        }

        $comportamentosValidos = ['trabalho', 'falta_justificada_remunerada', 'falta_justificada_nao_remunerada', 'folga'];
        if (empty($body['comportamento']) || !in_array($body['comportamento'], $comportamentosValidos, true)) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'Comportamento inválido.']);
        }

        $cor = null;
        if (!empty($body['cor'])) {
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $body['cor'])) {
                return $this->json(400, ['erro' => true, 'mensagem' => 'O campo cor deve ter o formato #RRGGBB.']);
            }
            $cor = $body['cor'];
        }

        $db = $this->db();

        // Verificar unicidade do código
        $stmt = $db->prepare("SELECT id FROM tipos_justificacao WHERE codigo = :codigo LIMIT 1");
        $stmt->execute([':codigo' => $body['codigo']]);
        if ($stmt->fetch()) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'Já existe um tipo de justificação com este código.']);
        }

        $hasCor = true;
        try {
            $db->query("SELECT cor FROM tipos_justificacao LIMIT 1");
        } catch (\PDOException $e) {
            $hasCor = false;
        }

        if ($hasCor) {
            $stmt = $db->prepare("
                INSERT INTO tipos_justificacao (codigo, nome, comportamento, cor, activo)
                VALUES (:codigo, :nome, :comportamento, :cor, 1)
            ");
            $stmt->execute([
                ':codigo'        => $body['codigo'],
                ':nome'          => $body['nome'],
                ':comportamento' => $body['comportamento'],
                ':cor'           => $cor,
            ]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO tipos_justificacao (codigo, nome, comportamento, activo)
                VALUES (:codigo, :nome, :comportamento, 1)
            ");
            $stmt->execute([
                ':codigo'        => $body['codigo'],
                ':nome'          => $body['nome'],
                ':comportamento' => $body['comportamento'],
            ]);
        }

        return $this->json(201, [
            'mensagem' => 'Tipo de justificação criado com sucesso.',
            'id'       => (int) $db->lastInsertId(),
        ]);
    }

    /**
     * PUT /api/tipos-justificacao/{id}
     */
    public function actualizar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $db   = $this->db();

        $stmt = $db->prepare("SELECT id FROM tipos_justificacao WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Tipo de justificação não encontrado.']);
        }

        if (empty($body['nome'])) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'O campo nome é obrigatório.']);
        }

        $comportamentosValidos = ['trabalho', 'falta_justificada_remunerada', 'falta_justificada_nao_remunerada', 'folga'];
        if (empty($body['comportamento']) || !in_array($body['comportamento'], $comportamentosValidos, true)) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'Comportamento inválido.']);
        }

        $hasCor = true;
        try {
            $db->query("SELECT cor FROM tipos_justificacao LIMIT 1");
        } catch (\PDOException $e) {
            $hasCor = false;
        }

        $sql = "UPDATE tipos_justificacao SET nome = :nome, comportamento = :comportamento";
        $params = [
            ':nome'          => $body['nome'],
            ':comportamento' => $body['comportamento'],
            ':id'            => $id,
        ];

        if ($hasCor && array_key_exists('cor', $body)) {
            $cor = null;
            if ($body['cor'] !== null && $body['cor'] !== "") {
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $body['cor'])) {
                    return $this->json(400, ['erro' => true, 'mensagem' => 'O campo cor deve ter o formato #RRGGBB.']);
                }
                $cor = $body['cor'];
            }
            $sql .= ", cor = :cor";
            $params[':cor'] = $cor;
        }

        $sql .= " WHERE id = :id";

        $db->prepare($sql)->execute($params);

        return $this->json(200, ['mensagem' => 'Tipo de justificação actualizado com sucesso.']);
    }

    /**
     * PATCH /api/tipos-justificacao/{id}/activo
     */
    public function alternarActivo(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $db = $this->db();

        $stmt = $db->prepare("SELECT activo FROM tipos_justificacao WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $registo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$registo) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Tipo de justificação não encontrado.']);
        }

        $novoActivo = $registo['activo'] ? 0 : 1;

        $db->prepare("UPDATE tipos_justificacao SET activo = :activo WHERE id = :id")->execute([
            ':activo' => $novoActivo,
            ':id'     => $id,
        ]);

        $mensagem = $novoActivo ? 'Tipo de justificação activado com sucesso.' : 'Tipo de justificação desactivado com sucesso.';

        return $this->json(200, ['mensagem' => $mensagem]);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
