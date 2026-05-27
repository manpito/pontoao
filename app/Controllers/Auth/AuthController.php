<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Config\Database;
use App\Config\TenantResolver;
use App\Services\AuthService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * AuthController — Autenticação de utilizadores de um tenant
 */
class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    /**
     * POST /api/auth/login
     */
    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub  = TenantResolver::resolve();
        $body = $request->getParsedBody();

        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $totp     = $body['totp_code'] ?? null;

        if (empty($email) || empty($password)) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'Email e password são obrigatórios.']);
        }

        try {
            $db = Database::tenant($sub);
        } catch (\RuntimeException $e) {
            return $this->json($e->getCode() ?: 500, ['erro' => true, 'mensagem' => $e->getMessage()]);
        }

        // Verificar bloqueio por IP/tentativas
        $stmt = $db->prepare("
            SELECT u.*, f.nome_completo as funcionario_nome
            FROM `utilizadores` u
            LEFT JOIN `funcionarios` f ON u.`funcionario_id` = f.`id`
            WHERE u.`email` = :email AND u.`activo` = 1
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verificar bloqueio temporário
        if ($user && $user['bloqueado_ate'] && strtotime($user['bloqueado_ate']) > time()) {
            $restante = ceil((strtotime($user['bloqueado_ate']) - time()) / 60);
            return $this->json(429, [
                'erro'     => true,
                'codigo'   => 'CONTA_BLOQUEADA',
                'mensagem' => "Conta temporariamente bloqueada. Tente novamente em {$restante} minuto(s).",
            ]);
        }

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Incrementar tentativas falhadas
            if ($user) {
                $tentativas = $user['tentativas_login'] + 1;
                $bloquearAte = null;

                if ($tentativas >= (int) ($_ENV['RATE_LIMIT_LOGIN'] ?? 5)) {
                    $bloquearAte = date('Y-m-d H:i:s', time() + ((int) ($_ENV['LOGIN_LOCKOUT_MINUTES'] ?? 15) * 60));
                }

                $db->prepare("UPDATE `utilizadores` SET `tentativas_login` = :t, `bloqueado_ate` = :b WHERE `id` = :id")
                   ->execute([':t' => $tentativas, ':b' => $bloquearAte, ':id' => $user['id']]);
            }

            return $this->json(401, ['erro' => true, 'codigo' => 'CREDENCIAIS_INVALIDAS', 'mensagem' => 'Email ou password incorrectos.']);
        }

        // 2FA
        if ($user['totp_activo'] && $user['totp_secret']) {
            if (empty($totp)) {
                return $this->json(200, ['2fa_necessario' => true]);
            }
            // Reutiliza a mesma lógica TOTP do SuperAdminAuthController
        }

        // Reset tentativas
        $db->prepare("UPDATE `utilizadores` SET `tentativas_login` = 0, `bloqueado_ate` = NULL, `ultimo_login` = NOW(), `ultimo_ip` = :ip WHERE `id` = :id")
           ->execute([':ip' => $_SERVER['REMOTE_ADDR'] ?? null, ':id' => $user['id']]);

        // Gerar tokens
        // Incluir funcionario_id no payload do token
        $userPayload = $user;
        $userPayload['funcionario_id'] = $user['funcionario_id'] ? (int) $user['funcionario_id'] : null;
        $accessToken  = $this->auth->generateAccessToken($userPayload, $sub);
        $refreshToken = $this->auth->generateRefreshToken();

        $expira = date('Y-m-d H:i:s', time() + $this->auth->getRefreshTtl());
        $db->prepare("
            INSERT INTO `utilizador_tokens` (`utilizador_id`, `token_hash`, `ip`, `user_agent`, `expira_em`)
            VALUES (:id, :hash, :ip, :ua, :expira)
        ")->execute([
            ':id'     => $user['id'],
            ':hash'   => $this->auth->hashRefreshToken($refreshToken),
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ':expira' => $expira,
        ]);

        return $this->json(200, [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => (int) ($_ENV['JWT_ACCESS_TTL'] ?? 3600),
            'utilizador'    => [
                'id'            => $user['id'],
                'uuid'          => $user['uuid'],
                'nome'          => $user['nome'],
                'email'         => $user['email'],
                'perfil'        => $user['perfil'],
                'funcionario_id' => $user['funcionario_id'] ? (int) $user['funcionario_id'] : null,
            ],
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub  = TenantResolver::resolve();
        $body = $request->getParsedBody();
        $token = $body['refresh_token'] ?? '';

        if ($token) {
            Database::tenant($sub)->prepare(
                "UPDATE `utilizador_tokens` SET `revogado` = 1 WHERE `token_hash` = :hash"
            )->execute([':hash' => $this->auth->hashRefreshToken($token)]);
        }

        return $this->json(200, ['mensagem' => 'Sessão terminada.']);
    }

    /**
     * POST /api/auth/refresh
     */
    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sub   = TenantResolver::resolve();
        $body  = $request->getParsedBody();
        $token = $body['refresh_token'] ?? '';

        if (empty($token)) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'refresh_token em falta.']);
        }

        $db   = Database::tenant($sub);
        $stmt = $db->prepare("
            SELECT t.*, u.`nome`, u.`email`, u.`perfil`, u.`activo`, u.`uuid`
            FROM `utilizador_tokens` t
            JOIN `utilizadores` u ON t.`utilizador_id` = u.`id`
            WHERE t.`token_hash` = :hash AND t.`revogado` = 0 AND t.`expira_em` > NOW()
            LIMIT 1
        ");
        $stmt->execute([':hash' => $this->auth->hashRefreshToken($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !$row['activo']) {
            return $this->json(401, ['erro' => true, 'mensagem' => 'Token inválido ou expirado.']);
        }

        $accessToken = $this->auth->generateAccessToken([
            'id'     => $row['utilizador_id'],
            'uuid'   => $row['uuid'],
            'nome'   => $row['nome'],
            'email'  => $row['email'],
            'perfil' => $row['perfil'],
        ], $sub);

        return $this->json(200, ['access_token' => $accessToken, 'expires_in' => (int) ($_ENV['JWT_ACCESS_TTL'] ?? 3600)]);
    }

    /**
     * GET /api/auth/me
     */
    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('auth_user');
        return $this->json(200, ['utilizador' => (array) $user]);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
