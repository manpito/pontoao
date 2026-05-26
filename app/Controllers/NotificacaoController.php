<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class NotificacaoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/notificacoes
     * Devolve notificações do utilizador autenticado
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user   = $request->getAttribute('auth_user');
        $params = $request->getQueryParams();

        if (!$user) {
            return $this->json(401, ['erro' => true, 'mensagem' => 'Não autenticado.']);
        }

        $where = ['utilizador_id = :uid'];
        $bind  = [':uid' => (int) $user->sub];

        if (isset($params['lida'])) {
            $where[]      = 'lida = :lida';
            $bind[':lida'] = (int) $params['lida'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->db()->prepare("
            SELECT id, tipo, titulo, mensagem, lida, criado_em
            FROM notificacoes
            WHERE {$whereStr}
            ORDER BY criado_em DESC
            LIMIT 50
        ");
        $stmt->execute($bind);

        $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Contar não lidas
        $naoLidas = array_filter($notificacoes, fn($n) => !$n['lida']);

        return $this->json(200, [
            'dados'     => $notificacoes,
            'nao_lidas' => count($naoLidas),
        ]);
    }

    /**
     * PUT /api/notificacoes/{id}/ler
     */
    public function marcarLida(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $user = $request->getAttribute('auth_user');

        if (!$user) {
            return $this->json(401, ['erro' => true, 'mensagem' => 'Não autenticado.']);
        }

        $stmt = $this->db()->prepare("
            UPDATE notificacoes SET lida = 1
            WHERE id = :id AND utilizador_id = :uid
        ");
        $stmt->execute([':id' => $id, ':uid' => (int) $user->sub]);

        if ($stmt->rowCount() === 0) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Notificação não encontrada.']);
        }

        return $this->json(200, ['mensagem' => 'Notificação marcada como lida.']);
    }

    /**
     * Método estático para criar notificações internamente (usado por outros controllers)
     */
    public static function criar(PDO $db, int $utilizadorId, string $tipo, string $titulo, string $mensagem): void
    {
        $db->prepare("
            INSERT INTO notificacoes (utilizador_id, tipo, titulo, mensagem)
            VALUES (:uid, :tipo, :titulo, :mensagem)
        ")->execute([
            ':uid'      => $utilizadorId,
            ':tipo'     => $tipo,
            ':titulo'   => $titulo,
            ':mensagem' => $mensagem,
        ]);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
