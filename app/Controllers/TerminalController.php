<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * TerminalController — Gestão de terminais biométricos e grupos de segurança
 */
class TerminalController
{
    /**
     * GET /api/terminais
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->query("
            SELECT r.*, d.nome AS departamento_default_nome
            FROM relogios r
            LEFT JOIN departamentos d ON r.default_departamento_id = d.id
            ORDER BY r.nome ASC
        ");
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Adicionar estado online/offline baseado no último heartbeat (5 min)
        foreach ($dados as &$d) {
            $ultimo = $d['ultimo_heartbeat'] ? strtotime($d['ultimo_heartbeat']) : 0;
            $d['online'] = (time() - $ultimo) < 300;
        }

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * POST /api/terminais
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub  = TenantResolver::resolve();
        $db   = Database::tenant($sub);
        $body = $request->getParsedBody();

        $nome = trim($body['nome'] ?? '');
        $ip   = trim($body['ip'] ?? '');

        if (empty($nome) || empty($ip)) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Nome e IP são obrigatórios.']);
        }

        $stmt = $db->prepare("
            INSERT INTO relogios (nome, localizacao, ip, porta, modelo, default_departamento_id, activo, api_key_hash)
            VALUES (:nome, :loc, :ip, :porta, :modelo, :dep_id, 1, :key)
        ");

        $stmt->execute([
            ':nome'    => $nome,
            ':loc'     => $body['localizacao'] ?? null,
            ':ip'      => $ip,
            ':porta'   => (int) ($body['porta'] ?? 4370),
            ':modelo'  => $body['modelo'] ?? 'zkteco',
            ':dep_id'  => $body['default_departamento_id'] ? (int) $body['default_departamento_id'] : null,
            ':key'     => hash('sha256', bin2hex(random_bytes(16))) // API key inicial aleatória
        ]);

        return $this->json($response, 201, ['mensagem' => 'Terminal registado com sucesso.', 'id' => $db->lastInsertId()]);
    }

    /**
     * GET /api/terminais/{id}
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->prepare("SELECT * FROM relogios WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $terminal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$terminal) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Terminal não encontrado.']);
        }

        // Departamentos associados
        $stmtDep = $db->prepare("
            SELECT d.id, d.nome
            FROM departamentos d
            JOIN relogio_departamentos rd ON d.id = rd.departamento_id
            WHERE rd.relogio_id = :id
        ");
        $stmtDep->execute([':id' => $id]);
        $terminal['departamentos'] = $stmtDep->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $terminal]);
    }

    /**
     * PUT /api/terminais/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $sub  = TenantResolver::resolve();
        $db   = Database::tenant($sub);
        $body = $request->getParsedBody();

        $nome = trim($body['nome'] ?? '');
        $ip   = trim($body['ip'] ?? '');

        if (empty($nome) || empty($ip)) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Nome e IP são obrigatórios.']);
        }

        $stmt = $db->prepare("
            UPDATE relogios
            SET nome = :nome, localizacao = :loc, ip = :ip, porta = :porta,
                modelo = :modelo, default_departamento_id = :dep_id
            WHERE id = :id
        ");

        $stmt->execute([
            ':nome'    => $nome,
            ':loc'     => $body['localizacao'] ?? null,
            ':ip'      => $ip,
            ':porta'   => (int) ($body['porta'] ?? 4370),
            ':modelo'  => $body['modelo'] ?? 'zkteco',
            ':dep_id'  => $body['default_departamento_id'] ? (int) $body['default_departamento_id'] : null,
            ':id'      => $id
        ]);

        return $this->json($response, 200, ['mensagem' => 'Terminal actualizado com sucesso.']);
    }

    /**
     * DELETE /api/terminais/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $db->prepare("DELETE FROM relogios WHERE id = :id")->execute([':id' => $id]);

        return $this->json($response, 200, ['mensagem' => 'Terminal removido com sucesso.']);
    }

    /**
     * GET /api/terminais/{id}/departamentos
     */
    public function listarDepartamentos(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->prepare("
            SELECT d.id, d.nome
            FROM departamentos d
            JOIN relogio_departamentos rd ON d.id = rd.departamento_id
            WHERE rd.relogio_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * POST /api/terminais/{id}/departamentos
     */
    public function associarDepartamento(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id    = (int) $args['id'];
        $body  = $request->getParsedBody();
        $depId = (int) ($body['departamento_id'] ?? 0);

        if (!$depId) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'ID do departamento é obrigatório.']);
        }

        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->prepare("INSERT IGNORE INTO relogio_departamentos (relogio_id, departamento_id) VALUES (:rid, :did)");
        $stmt->execute([':rid' => $id, ':did' => $depId]);

        return $this->json($response, 201, ['mensagem' => 'Departamento associado com sucesso.']);
    }

    /**
     * DELETE /api/terminais/{id}/departamentos/{dep_id}
     */
    public function removerDepartamento(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id    = (int) $args['id'];
        $depId = (int) $args['dep_id'];
        $sub   = TenantResolver::resolve();
        $db    = Database::tenant($sub);

        $db->prepare("DELETE FROM relogio_departamentos WHERE relogio_id = :rid AND departamento_id = :did")
           ->execute([':rid' => $id, ':did' => $depId]);

        return $this->json($response, 200, ['mensagem' => 'Associação removida com sucesso.']);
    }

    /**
     * GET /api/terminais/{id}/ping
     */
    public function ping(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $sub = TenantResolver::resolve();
        $db  = Database::tenant($sub);

        $stmt = $db->prepare("SELECT ip, porta FROM relogios WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $rel = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rel) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Terminal não encontrado.']);
        }

        $start = microtime(true);
        $fp    = @fsockopen($rel['ip'], (int) $rel['porta'], $errno, $errstr, 2);
        $end   = microtime(true);

        if ($fp) {
            fclose($fp);
            $latencia = round(($end - $start) * 1000, 2);
            return $this->json($response, 200, [
                'online'   => true,
                'latencia' => $latencia . 'ms',
                'mensagem' => "Terminal online ({$latencia}ms)."
            ]);
        }

        return $this->json($response, 200, [
            'online'   => false,
            'erro'     => $errstr ?: 'Connection timed out',
            'mensagem' => 'Terminal offline ou inacessível.'
        ]);
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
