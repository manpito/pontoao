<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use RuntimeException;

/**
 * TenantManager — Ciclo de vida completo dos tenants
 *
 * Criação: reserva subdomínio → cria DB isolada → corre migrations
 *          → insere na saas_master → cria super_admin_tenant
 */
class TenantManager
{
    private PDO $master;

    public function __construct()
    {
        $this->master = Database::master();
    }

    /**
     * Cria um novo tenant completo
     *
     * @param array $data { nome_empresa, nif, email_contacto, subdominio, plano_id, ... }
     * @param array $adminData { nome, email, password }
     */
    public function create(array $data, array $adminData): array
    {
        $this->validateSubdominio($data['subdominio']);

        $this->master->beginTransaction();

        try {
            // 1. Inserir tenant na master (sem credenciais DB ainda)
            $stmt = $this->master->prepare("
                INSERT INTO `tenants` (
                    `uuid`, `nome_empresa`, `nif`, `email_contacto`, `telefone`,
                    `morada`, `municipio`, `provincia`, `subdominio`,
                    `db_nome`, `db_host`, `db_usuario`, `db_password_enc`,
                    `plano_id`, `estado`, `trial_ate`
                ) VALUES (
                    UUID(), :nome_empresa, :nif, :email_contacto, :telefone,
                    :morada, :municipio, :provincia, :subdominio,
                    '', '127.0.0.1', '', '',
                    :plano_id, 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY)
                )
            ");

            $stmt->execute([
                ':nome_empresa'   => $data['nome_empresa'],
                ':nif'            => $data['nif'],
                ':email_contacto' => $data['email_contacto'],
                ':telefone'       => $data['telefone'] ?? null,
                ':morada'         => $data['morada'] ?? null,
                ':municipio'      => $data['municipio'] ?? null,
                ':provincia'      => $data['provincia'] ?? null,
                ':subdominio'     => $data['subdominio'],
                ':plano_id'       => $data['plano_id'],
            ]);

            $tenantId = (int) $this->master->lastInsertId();

            // 2. Criar base de dados MySQL isolada
            $dbCredentials = Database::createTenantDatabase([
                'id'        => $tenantId,
                'subdominio' => $data['subdominio'],
            ]);

            // 3. Actualizar tenant com credenciais da DB
            $this->master->prepare("
                UPDATE `tenants`
                SET `db_nome` = :db_nome, `db_usuario` = :db_usuario, `db_password_enc` = :db_password_enc
                WHERE `id` = :id
            ")->execute([
                ':db_nome'         => $dbCredentials['db_nome'],
                ':db_usuario'      => $dbCredentials['db_usuario'],
                ':db_password_enc' => $dbCredentials['db_password_enc'],
                ':id'              => $tenantId,
            ]);

            // 4. Criar utilizador super_admin_tenant na DB do tenant
            $tenantDb = Database::tenant($data['subdominio']);
            $this->createTenantAdmin($tenantDb, $adminData);

            // 5. Log de auditoria
            $this->logAuditoria(null, $tenantId, 'tenant.criado', 'Novo tenant criado', null, [
                'nome_empresa' => $data['nome_empresa'],
                'subdominio'   => $data['subdominio'],
                'plano_id'     => $data['plano_id'],
            ]);

            $this->master->commit();

            return $this->findById($tenantId);

        } catch (\Exception $e) {
            $this->master->rollBack();

            // Tentar limpar a DB criada se a transaction falhou
            if (isset($dbCredentials)) {
                try {
                    Database::dropTenantDatabase($dbCredentials['db_nome'], $dbCredentials['db_usuario']);
                } catch (\Exception) { /* ignorar */ }
            }

            throw new RuntimeException('Falha ao criar tenant: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Suspende um tenant — utilizadores não conseguem fazer login
     */
    public function suspend(int $tenantId, int $superAdminId): void
    {
        $tenant = $this->findById($tenantId);

        if (!$tenant) {
            throw new RuntimeException('Tenant não encontrado.', 404);
        }

        $this->master->prepare("UPDATE `tenants` SET `estado` = 'suspenso' WHERE `id` = :id")
             ->execute([':id' => $tenantId]);

        $this->logAuditoria($superAdminId, $tenantId, 'tenant.suspenso', 'Tenant suspenso', ['estado' => $tenant['estado']], ['estado' => 'suspenso']);
    }

    /**
     * Reactiva um tenant suspenso
     */
    public function activate(int $tenantId, int $superAdminId): void
    {
        $this->master->prepare("UPDATE `tenants` SET `estado` = 'activo' WHERE `id` = :id")
             ->execute([':id' => $tenantId]);

        $this->logAuditoria($superAdminId, $tenantId, 'tenant.activado', 'Tenant reactivado', null, ['estado' => 'activo']);
    }

    /**
     * Elimina tenant completamente — IRREVERSÍVEL
     * Requer confirmação explícita (confirmacao = "ELIMINAR")
     */
    public function destroy(int $tenantId, int $superAdminId, string $confirmacao): void
    {
        if ($confirmacao !== 'ELIMINAR') {
            throw new RuntimeException('Confirmação inválida. Escreva "ELIMINAR" para confirmar.', 400);
        }

        $tenant = $this->findById($tenantId);

        if (!$tenant) {
            throw new RuntimeException('Tenant não encontrado.', 404);
        }

        $this->master->beginTransaction();

        try {
            // Eliminar DB do tenant
            Database::dropTenantDatabase($tenant['db_nome'], $tenant['db_usuario']);

            // Eliminar registos relacionados na master
            $this->master->prepare("DELETE FROM `tenant_migrations` WHERE `tenant_id` = :id")->execute([':id' => $tenantId]);
            $this->master->prepare("UPDATE `tenants` SET `estado` = 'cancelado' WHERE `id` = :id")->execute([':id' => $tenantId]);

            $this->logAuditoria($superAdminId, $tenantId, 'tenant.eliminado', 'Tenant e DB eliminados', $tenant, null);

            $this->master->commit();

        } catch (\Exception $e) {
            $this->master->rollBack();
            throw new RuntimeException('Falha ao eliminar tenant: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Lista todos os tenants com métricas básicas
     */
    public function list(array $filtros = []): array
    {
        $where  = '1=1';
        $params = [];

        if (!empty($filtros['estado'])) {
            $where .= ' AND t.`estado` = :estado';
            $params[':estado'] = $filtros['estado'];
        }

        if (!empty($filtros['search'])) {
            $where .= ' AND (t.`nome_empresa` LIKE :search OR t.`subdominio` LIKE :search OR t.`email_contacto` LIKE :search)';
            $params[':search'] = '%' . $filtros['search'] . '%';
        }

        $stmt = $this->master->prepare("
            SELECT t.`id`, t.`uuid`, t.`nome_empresa`, t.`nif`, t.`email_contacto`,
                   t.`subdominio`, t.`estado`, t.`trial_ate`, t.`data_inicio_plano`, t.`data_fim_plano`,
                   t.`criado_em`, p.`nome` as plano_nome, p.`slug` as plano_slug,
                   t.`schema_versao`
            FROM `tenants` t
            JOIN `planos` p ON t.`plano_id` = p.`id`
            WHERE {$where}
            ORDER BY t.`criado_em` DESC
        ");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->master->prepare("
            SELECT t.*, p.`nome` as plano_nome, p.`slug` as plano_slug
            FROM `tenants` t
            JOIN `planos` p ON t.`plano_id` = p.`id`
            WHERE t.`id` = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Métricas para o dashboard do super-admin
     */
    public function getDashboardMetrics(): array
    {
        $metrics = [];

        $metrics['tenants_activos'] = $this->master->query(
            "SELECT COUNT(*) FROM `tenants` WHERE `estado` = 'activo'"
        )->fetchColumn();

        $metrics['tenants_trial'] = $this->master->query(
            "SELECT COUNT(*) FROM `tenants` WHERE `estado` = 'trial'"
        )->fetchColumn();

        $metrics['tenants_suspensos'] = $this->master->query(
            "SELECT COUNT(*) FROM `tenants` WHERE `estado` = 'suspenso'"
        )->fetchColumn();

        $metrics['trial_a_expirar_7d'] = $this->master->query(
            "SELECT COUNT(*) FROM `tenants` WHERE `estado` = 'trial' AND `trial_ate` BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();

        $metrics['contratos_a_expirar_30d'] = $this->master->query(
            "SELECT COUNT(*) FROM `tenants` WHERE `estado` = 'activo' AND `data_fim_plano` BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();

        $metrics['receita_mensal_aoa'] = $this->master->query(
            "SELECT COALESCE(SUM(p.preco_mensal_aoa), 0) FROM `tenants` t JOIN `planos` p ON t.plano_id = p.id WHERE t.estado = 'activo'"
        )->fetchColumn();

        $metrics['planos'] = $this->master->query(
            "SELECT p.nome, p.slug, COUNT(t.id) as total FROM `planos` p LEFT JOIN `tenants` t ON t.plano_id = p.id AND t.estado IN ('activo','trial') GROUP BY p.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        return $metrics;
    }

    private function createTenantAdmin(PDO $tenantDb, array $adminData): void
    {
        $uuid  = $this->generateUuid();
        $hash  = password_hash($adminData['password'], PASSWORD_BCRYPT, ['cost' => (int) ($_ENV['BCRYPT_COST'] ?? 12)]);

        $tenantDb->prepare("
            INSERT INTO `utilizadores` (`uuid`, `nome`, `email`, `password_hash`, `perfil`)
            VALUES (:uuid, :nome, :email, :hash, 'super_admin_tenant')
        ")->execute([
            ':uuid'  => $uuid,
            ':nome'  => $adminData['nome'],
            ':email' => $adminData['email'],
            ':hash'  => $hash,
        ]);
    }

    private function validateSubdominio(string $sub): void
    {
        if (!preg_match('/^[a-z0-9\-]{2,60}$/', $sub)) {
            throw new RuntimeException('Subdomínio inválido. Use apenas letras minúsculas, números e hífens.', 400);
        }

        $reservados = ['www', 'admin', 'api', 'mail', 'ftp', 'smtp', 'pop', 'imap', 'cpanel', 'webmail', 'bridge'];
        if (in_array($sub, $reservados, true)) {
            throw new RuntimeException("Subdomínio '{$sub}' está reservado.", 400);
        }

        $stmt = $this->master->prepare("SELECT id FROM `tenants` WHERE `subdominio` = :sub LIMIT 1");
        $stmt->execute([':sub' => $sub]);
        if ($stmt->fetch()) {
            throw new RuntimeException("Subdomínio '{$sub}' já está em uso.", 409);
        }
    }

    private function logAuditoria(?int $superAdminId, int $tenantId, string $accao, string $descricao, ?array $antes, ?array $depois): void
    {
        $this->master->prepare("
            INSERT INTO `log_auditoria_master`
                (`super_admin_id`, `tenant_id`, `accao`, `descricao`, `dados_antes`, `dados_depois`, `ip`, `user_agent`)
            VALUES
                (:sad, :tid, :accao, :desc, :antes, :depois, :ip, :ua)
        ")->execute([
            ':sad'    => $superAdminId,
            ':tid'    => $tenantId,
            ':accao'  => $accao,
            ':desc'   => $descricao,
            ':antes'  => $antes ? json_encode($antes) : null,
            ':depois' => $depois ? json_encode($depois) : null,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
