<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Config\Database;
use App\Services\AuthService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Autenticação de Super-Administradores
 * Acesso ao painel central admin.tuaplataforma.ao
 */
class SuperAdminAuthController
{
    private AuthService $auth;
    private PDO $db;

    public function __construct()
    {
        $this->auth = new AuthService();
        $this->db   = Database::master();
    }

    /**
     * POST /super-admin/auth/login
     */
    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $totpCode = $body['totp_code'] ?? null;

        if (empty($email) || empty($password)) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'Email e password são obrigatórios.']);
        }

        $stmt = $this->db->prepare("SELECT * FROM `super_admins` WHERE `email` = :email AND `activo` = 1 LIMIT 1");
        $stmt->execute([':email' => $email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return $this->json(401, ['erro' => true, 'codigo' => 'CREDENCIAIS_INVALIDAS', 'mensagem' => 'Email ou password incorrectos.']);
        }

        // Verificar 2FA se activado
        if ($admin['totp_activo'] && $admin['totp_secret']) {
            if (empty($totpCode)) {
                return $this->json(200, ['2fa_necessario' => true, 'mensagem' => 'Introduza o código de autenticação.']);
            }

            if (!$this->verifyTotp($admin['totp_secret'], $totpCode)) {
                return $this->json(401, ['erro' => true, 'codigo' => 'TOTP_INVALIDO', 'mensagem' => 'Código de autenticação inválido.']);
            }
        }

        // Gerar tokens
        $accessToken  = $this->auth->generateAccessToken([
            'id'     => $admin['id'],
            'uuid'   => $admin['id'] . '-super',
            'nome'   => $admin['nome'],
            'email'  => $admin['email'],
            'perfil' => 'super_admin',
        ]);
        $refreshToken = $this->auth->generateRefreshToken();

        // Guardar refresh token
        $expira = date('Y-m-d H:i:s', time() + $this->auth->getRefreshTtl());
        $this->db->prepare("
            INSERT INTO `super_admin_tokens` (`super_admin_id`, `token_hash`, `ip`, `user_agent`, `expira_em`)
            VALUES (:id, :hash, :ip, :ua, :expira)
        ")->execute([
            ':id'     => $admin['id'],
            ':hash'   => $this->auth->hashRefreshToken($refreshToken),
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ':expira' => $expira,
        ]);

        // Actualizar último login
        $this->db->prepare("UPDATE `super_admins` SET `ultimo_login` = NOW(), `ultimo_ip` = :ip WHERE `id` = :id")
             ->execute([':ip' => $_SERVER['REMOTE_ADDR'] ?? null, ':id' => $admin['id']]);

        return $this->json(200, [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => (int) ($_ENV['JWT_ACCESS_TTL'] ?? 3600),
            'admin'         => [
                'id'    => $admin['id'],
                'nome'  => $admin['nome'],
                'email' => $admin['email'],
            ],
        ]);
    }

    /**
     * POST /super-admin/auth/logout
     */
    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = $request->getParsedBody();
        $token = $body['refresh_token'] ?? '';

        if ($token) {
            $this->db->prepare("UPDATE `super_admin_tokens` SET `revogado` = 1 WHERE `token_hash` = :hash")
                 ->execute([':hash' => $this->auth->hashRefreshToken($token)]);
        }

        return $this->json(200, ['mensagem' => 'Sessão terminada com sucesso.']);
    }

    /**
     * POST /super-admin/auth/refresh
     */
    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = $request->getParsedBody();
        $token = $body['refresh_token'] ?? '';

        if (empty($token)) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'refresh_token em falta.']);
        }

        $stmt = $this->db->prepare("
            SELECT t.*, a.`nome`, a.`email`, a.`activo`
            FROM `super_admin_tokens` t
            JOIN `super_admins` a ON t.`super_admin_id` = a.`id`
            WHERE t.`token_hash` = :hash AND t.`revogado` = 0 AND t.`expira_em` > NOW()
            LIMIT 1
        ");
        $stmt->execute([':hash' => $this->auth->hashRefreshToken($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !$row['activo']) {
            return $this->json(401, ['erro' => true, 'mensagem' => 'Refresh token inválido ou expirado.']);
        }

        $accessToken = $this->auth->generateAccessToken([
            'id'     => $row['super_admin_id'],
            'uuid'   => $row['super_admin_id'] . '-super',
            'nome'   => $row['nome'],
            'email'  => $row['email'],
            'perfil' => 'super_admin',
        ]);

        return $this->json(200, [
            'access_token' => $accessToken,
            'expires_in'   => (int) ($_ENV['JWT_ACCESS_TTL'] ?? 3600),
        ]);
    }

    /**
     * Verificação TOTP simples (compatível com Google Authenticator)
     * RFC 6238 — janela de ±1 período (30 segundos)
     */
    private function verifyTotp(string $secret, string $code): bool
    {
        $time     = floor(time() / 30);
        $decodado = base64_decode($secret);

        for ($i = -1; $i <= 1; $i++) {
            $t    = pack('N*', 0) . pack('N*', $time + $i);
            $hash = hash_hmac('sha1', $t, $decodado, true);
            $off  = ord($hash[19]) & 0xf;
            $otp  = (
                ((ord($hash[$off]) & 0x7f) << 24) |
                ((ord($hash[$off + 1]) & 0xff) << 16) |
                ((ord($hash[$off + 2]) & 0xff) << 8)  |
                (ord($hash[$off + 3]) & 0xff)
            ) % 1000000;

            if (str_pad((string) $otp, 6, '0', STR_PAD_LEFT) === $code) {
                return true;
            }
        }

        return false;
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
