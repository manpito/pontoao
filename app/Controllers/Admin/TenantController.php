<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\TenantManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * TenantController — Gestão completa de tenants pelo super-admin
 */
class TenantController
{
    private TenantManager $manager;

    public function __construct()
    {
        $this->manager = new TenantManager();
    }

    /**
     * GET /super-admin/tenants
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params  = $request->getQueryParams();
        $tenants = $this->manager->list([
            'estado' => $params['estado'] ?? null,
            'search' => $params['search'] ?? null,
        ]);

        return $this->json(200, ['tenants' => $tenants, 'total' => count($tenants)]);
    }

    /**
     * POST /super-admin/tenants
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();

        // Validação básica
        $erros = $this->validar($body, [
            'nome_empresa'   => 'obrigatorio',
            'nif'            => 'obrigatorio',
            'email_contacto' => 'email',
            'subdominio'     => 'obrigatorio',
            'plano_id'       => 'inteiro',
            'admin_nome'     => 'obrigatorio',
            'admin_email'    => 'email',
            'admin_password' => 'password',
        ]);

        if (!empty($erros)) {
            return $this->json(422, ['erro' => true, 'erros' => $erros]);
        }

        try {
            $tenant = $this->manager->create(
                data: [
                    'nome_empresa'   => $body['nome_empresa'],
                    'nif'            => $body['nif'],
                    'email_contacto' => $body['email_contacto'],
                    'telefone'       => $body['telefone'] ?? null,
                    'morada'         => $body['morada'] ?? null,
                    'municipio'      => $body['municipio'] ?? null,
                    'provincia'      => $body['provincia'] ?? null,
                    'subdominio'     => strtolower(trim($body['subdominio'])),
                    'plano_id'       => (int) $body['plano_id'],
                ],
                adminData: [
                    'nome'     => $body['admin_nome'],
                    'email'    => $body['admin_email'],
                    'password' => $body['admin_password'],
                ]
            );

            return $this->json(201, [
                'mensagem' => 'Tenant criado com sucesso.',
                'tenant'   => $tenant,
                'url'      => 'https://' . $tenant['subdominio'] . '.' . parse_url($_ENV['APP_URL'], PHP_URL_HOST),
            ]);

        } catch (\RuntimeException $e) {
            $status = $e->getCode() >= 400 ? $e->getCode() : 500;
            return $this->json($status, ['erro' => true, 'mensagem' => $e->getMessage()]);
        }
    }

    /**
     * GET /super-admin/tenants/{id}
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenant = $this->manager->findById((int) $args['id']);

        if (!$tenant) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Tenant não encontrado.']);
        }

        // Remover campos sensíveis da resposta
        unset($tenant['db_password_enc']);

        return $this->json(200, ['tenant' => $tenant]);
    }

    /**
     * PUT /super-admin/tenants/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body   = $request->getParsedBody();
        $tenant = $this->manager->findById((int) $args['id']);

        if (!$tenant) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Tenant não encontrado.']);
        }

        $campos = ['nome_empresa', 'nif', 'email_contacto', 'telefone', 'morada', 'municipio', 'provincia', 'plano_id', 'estado', 'data_fim_plano'];
        $update = [];
        $params = [':id' => $args['id']];

        foreach ($campos as $campo) {
            if (array_key_exists($campo, $body)) {
                $update[] = "`{$campo}` = :{$campo}";
                $params[":{$campo}"] = $body[$campo] ?: null;
            }
        }

        if (empty($update)) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'Nenhum campo para actualizar.']);
        }

        \App\Config\Database::master()->prepare(
            "UPDATE `tenants` SET " . implode(', ', $update) . " WHERE `id` = :id"
        )->execute($params);

        // Reset password do admin do tenant se fornecida
        if (!empty($body['admin_password'])) {
            if (strlen($body['admin_password']) < 8) {
                return $this->json(422, ['erro' => true, 'mensagem' => 'A password deve ter no mínimo 8 caracteres.']);
            }
            try {
                $tenantData = $this->manager->findById((int) $args['id']);
                $tenantDb   = \App\Config\Database::tenant($tenantData['subdominio']);
                $hash = password_hash($body['admin_password'], PASSWORD_BCRYPT, ['cost' => (int) ($_ENV['BCRYPT_COST'] ?? 12)]);
                $tenantDb->prepare("UPDATE `utilizadores` SET `password_hash` = :hash WHERE `perfil` = 'super_admin_tenant' LIMIT 1")
                         ->execute([':hash' => $hash]);
            } catch (\Exception $e) {
                // Log mas não falha o update principal
            }
        }

        return $this->json(200, ['mensagem' => 'Empresa actualizada com sucesso.', 'tenant' => $this->manager->findById((int) $args['id'])]);
    }

    /**
     * POST /super-admin/tenants/{id}/suspend
     */
    public function suspend(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $adminId = $request->getAttribute('auth_super_admin_id');

        try {
            $this->manager->suspend((int) $args['id'], $adminId);
            return $this->json(200, ['mensagem' => 'Tenant suspenso.']);
        } catch (\RuntimeException $e) {
            return $this->json($e->getCode() ?: 500, ['erro' => true, 'mensagem' => $e->getMessage()]);
        }
    }

    /**
     * POST /super-admin/tenants/{id}/activate
     */
    public function activate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $adminId = $request->getAttribute('auth_super_admin_id');

        try {
            $this->manager->activate((int) $args['id'], $adminId);
            return $this->json(200, ['mensagem' => 'Tenant reactivado.']);
        } catch (\RuntimeException $e) {
            return $this->json($e->getCode() ?: 500, ['erro' => true, 'mensagem' => $e->getMessage()]);
        }
    }

    /**
     * DELETE /super-admin/tenants/{id}
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body        = $request->getParsedBody();
        $confirmacao = $body['confirmacao'] ?? '';
        $adminId     = $request->getAttribute('auth_super_admin_id');

        try {
            $this->manager->destroy((int) $args['id'], $adminId, $confirmacao);
            return $this->json(200, ['mensagem' => 'Tenant e respectiva base de dados eliminados permanentemente.']);
        } catch (\RuntimeException $e) {
            $status = $e->getCode() >= 400 ? $e->getCode() : 500;
            return $this->json($status, ['erro' => true, 'mensagem' => $e->getMessage()]);
        }
    }

    private function validar(array $dados, array $regras): array
    {
        $erros = [];

        foreach ($regras as $campo => $regra) {
            $valor = $dados[$campo] ?? '';

            switch ($regra) {
                case 'obrigatorio':
                    if (empty(trim((string) $valor))) {
                        $erros[$campo] = "O campo {$campo} é obrigatório.";
                    }
                    break;
                case 'email':
                    if (!filter_var($valor, FILTER_VALIDATE_EMAIL)) {
                        $erros[$campo] = "O campo {$campo} deve ser um email válido.";
                    }
                    break;
                case 'inteiro':
                    if (!is_numeric($valor) || (int) $valor <= 0) {
                        $erros[$campo] = "O campo {$campo} deve ser um número inteiro positivo.";
                    }
                    break;
                case 'password':
                    if (strlen((string) $valor) < 8) {
                        $erros[$campo] = "A password deve ter no mínimo 8 caracteres.";
                    }
                    break;
            }
        }

        return $erros;
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
