<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use App\Services\FeriadoService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class AnoLaboralController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve();
        return Database::tenant($sub);
    }

    /**
     * GET /api/anos-laborais
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db = $this->db();
        $stmt = $db->query("SELECT * FROM anos_laborais ORDER BY ano DESC");
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json($response, 200, ['dados' => $dados]);
    }

    /**
     * POST /api/anos-laborais
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];

        if (empty($body['ano'])) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'O campo ano é obrigatório.']);
        }

        $db = $this->db();

        // Verificar se já existe o ano
        $stmt = $db->prepare("SELECT id FROM anos_laborais WHERE ano = :ano");
        $stmt->execute(['ano' => $body['ano']]);
        if ($stmt->fetch()) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => "O ano laboral {$body['ano']} já existe."]);
        }

        $stmt = $db->prepare("
            INSERT INTO anos_laborais (ano, estado, dia_inicio_semana, dia_fim_semana)
            VALUES (:ano, 'pendente', :dia_inicio, :dia_fim)
        ");

        $stmt->execute([
            ':ano'       => $body['ano'],
            ':dia_inicio' => $body['dia_inicio_semana'] ?? 1,
            ':dia_fim'    => $body['dia_fim_semana'] ?? 5,
        ]);

        // Pré-carregar feriados móveis
        $feriadoService = new FeriadoService($db);
        $feriadoService->preCarregarFeriadosMoveis((int)$body['ano']);

        return $this->json($response, 201, [
            'mensagem' => "Ano laboral {$body['ano']} criado com sucesso.",
            'id'       => (int) $db->lastInsertId(),
        ]);
    }

    /**
     * POST /api/anos-laborais/{ano}/activar
     */
    public function activar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ano = $args['ano'];
        $db = $this->db();

        // Validar que não existe outro ano activo
        $stmt = $db->query("SELECT ano FROM anos_laborais WHERE estado = 'activo' LIMIT 1");
        $activo = $stmt->fetchColumn();
        if ($activo && $activo != $ano) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => "Já existe um ano laboral activo ({$activo})."]);
        }

        $userId = (int)($request->getAttribute('auth_user')->id ?? 0);

        $stmt = $db->prepare("
            UPDATE anos_laborais
            SET estado = 'activo', activado_em = NOW(), activado_por = :user
            WHERE ano = :ano
        ");
        $stmt->execute(['ano' => $ano, 'user' => $userId]);

        if ($stmt->rowCount() === 0) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Ano laboral não encontrado.']);
        }

        return $this->json($response, 200, ['mensagem' => "Ano laboral {$ano} activado com sucesso."]);
    }

    /**
     * POST /api/anos-laborais/{ano}/fechar
     */
    public function fechar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ano = $args['ano'];
        $db = $this->db();

        $stmt = $db->prepare("UPDATE anos_laborais SET estado = 'fechado' WHERE ano = :ano AND estado = 'activo'");
        $stmt->execute(['ano' => $ano]);

        if ($stmt->rowCount() === 0) {
            return $this->json($response, 400, ['erro' => true, 'mensagem' => 'Apenas anos activos podem ser fechados.']);
        }

        return $this->json($response, 200, ['mensagem' => "Ano laboral {$ano} fechado com sucesso."]);
    }

    /**
     * GET /api/anos-laborais/{ano}/feriados
     */
    public function feriados(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ano = (int) $args['ano'];
        $db = $this->db();

        // Lista feriados do ano, incluindo recorrentes
        $stmt = $db->prepare("
            SELECT * FROM feriados
            WHERE (recorrente = 1)
               OR (recorrente = 0 AND ano = :ano)
            ORDER BY data ASC
        ");
        $stmt->execute(['ano' => $ano]);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ajustar a data dos recorrentes para o ano em questão se necessário para visualização,
        // mas a query original retorna a data guardada (que pode ser de outro ano ou genérica).
        // Na UI costuma-se mostrar o mês/dia.

        foreach ($dados as &$f) {
            if ($f['recorrente']) {
                $f['data'] = $ano . '-' . date('m-d', strtotime($f['data']));
            }
        }

        // Ordenar por data novamente após ajuste
        usort($dados, function($a, $b) {
            return strcmp($a['data'], $b['data']);
        });

        return $this->json($response, 200, ['dados' => $dados]);
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
