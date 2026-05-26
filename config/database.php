<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Gestão de conexões de base de dados
 *
 * Master DB: saas_master — sempre acessível
 * Tenant DB: resolvida dinamicamente por subdomínio
 */
class Database
{
    private static ?PDO $masterConnection = null;
    private static array $tenantConnections = [];

    /**
     * Conexão à base de dados master (saas_master)
     */
    public static function master(): PDO
    {
        if (self::$masterConnection === null) {
            self::$masterConnection = self::createConnection(
                host: $_ENV['DB_MASTER_HOST'],
                port: (int) $_ENV['DB_MASTER_PORT'],
                dbname: $_ENV['DB_MASTER_DATABASE'],
                username: $_ENV['DB_MASTER_USERNAME'],
                password: $_ENV['DB_MASTER_PASSWORD'],
            );
        }

        return self::$masterConnection;
    }

    /**
     * Conexão à base de dados de um tenant específico
     * Resolução por subdomínio → credenciais na saas_master
     */
    public static function tenant(string $subdominio): PDO
    {
        if (isset(self::$tenantConnections[$subdominio])) {
            return self::$tenantConnections[$subdominio];
        }

        $tenant = self::resolveTenant($subdominio);

        if (!$tenant) {
            throw new RuntimeException("Tenant não encontrado: {$subdominio}", 404);
        }

        if ($tenant['estado'] === 'suspenso') {
            throw new RuntimeException("Conta suspensa. Contacte o suporte.", 403);
        }

        if ($tenant['estado'] === 'cancelado') {
            throw new RuntimeException("Conta cancelada.", 403);
        }

        $password = self::decryptDbPassword($tenant['db_password_enc']);

        $conn = self::createConnection(
            host: $tenant['db_host'],
            port: 3306,
            dbname: $tenant['db_nome'],
            username: $tenant['db_usuario'],
            password: $password,
        );

        self::$tenantConnections[$subdominio] = $conn;

        return $conn;
    }

    /**
     * Cria novo tenant: cria a base de dados, o utilizador MySQL e corre as migrations
     */
    public static function createTenantDatabase(array $tenantData): array
    {
        $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($tenantData['subdominio']));
        $dbNome = $_ENV['DB_TENANT_PREFIX'] . sprintf('%03d', $tenantData['id']) . '_' . $slug;
        $dbUser = 'tenant_' . sprintf('%03d', $tenantData['id']);
        $dbPass = self::generateSecurePassword();

        // Usar utilizador admin com permissão para criar DBs
        $adminPdo = self::createConnection(
            host: $_ENV['DB_MASTER_HOST'],
            port: (int) $_ENV['DB_MASTER_PORT'],
            dbname: 'information_schema',
            username: $_ENV['DB_ADMIN_USERNAME'],
            password: $_ENV['DB_ADMIN_PASSWORD'],
        );

        // Criar base de dados isolada
        $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbNome}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Criar utilizador dedicado com acesso apenas à sua DB
        $adminPdo->exec("CREATE USER IF NOT EXISTS '{$dbUser}'@'127.0.0.1' IDENTIFIED BY '{$dbPass}'");
        $adminPdo->exec("GRANT ALL PRIVILEGES ON `{$dbNome}`.* TO '{$dbUser}'@'127.0.0.1'");
        $adminPdo->exec("FLUSH PRIVILEGES");

        // Correr schema do tenant
        $tenantPdo = self::createConnection(
            host: $_ENV['DB_MASTER_HOST'],
            port: (int) $_ENV['DB_MASTER_PORT'],
            dbname: $dbNome,
            username: $dbUser,
            password: $dbPass,
        );

        self::runMigrations($tenantPdo, 'tenant');
        self::runSeeders($tenantPdo);

        return [
            'db_nome'        => $dbNome,
            'db_usuario'     => $dbUser,
            'db_password_enc' => self::encryptDbPassword($dbPass),
        ];
    }

    /**
     * Elimina a base de dados e utilizador de um tenant
     * ATENÇÃO: operação irreversível — requer confirmação explícita
     */
    public static function dropTenantDatabase(string $dbNome, string $dbUser): void
    {
        $adminPdo = self::createConnection(
            host: $_ENV['DB_MASTER_HOST'],
            port: (int) $_ENV['DB_MASTER_PORT'],
            dbname: 'information_schema',
            username: $_ENV['DB_ADMIN_USERNAME'],
            password: $_ENV['DB_ADMIN_PASSWORD'],
        );

        $adminPdo->exec("DROP DATABASE IF EXISTS `{$dbNome}`");
        $adminPdo->exec("DROP USER IF EXISTS '{$dbUser}'@'127.0.0.1'");
        $adminPdo->exec("FLUSH PRIVILEGES");
    }

    /**
     * Corre migrations de um directório sobre uma conexão PDO
     */
    public static function runMigrations(PDO $pdo, string $tipo): void
    {
        $dir = __DIR__ . "/../../database/migrations/{$tipo}";

        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*.sql');
        sort($files);

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            // Executar statement por statement para compatibilidade cPanel
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($s) => !empty($s) && !preg_match('/^--/', $s)
            );

            foreach ($statements as $stmt) {
                try {
                    $pdo->exec($stmt . ';');
                } catch (PDOException $e) {
                    // Ignorar erros "already exists" em ambientes de re-execução
                    if (!str_contains($e->getMessage(), 'already exists')) {
                        throw new RuntimeException("Migration falhou em {$file}: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Corre seeders sobre uma conexão tenant (feriados, configurações padrão)
     */
    private static function runSeeders(PDO $pdo): void
    {
        $seeders = require __DIR__ . '/../../database/seeders/holidays_angola.php';
        $ano = (int) date('Y');

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO `feriados` (`nome`, `data`, `tipo`, `meio_dia`, `recorrente`)
            VALUES (:nome, :data, :tipo, :meio_dia, :recorrente)
        ");

        foreach ($seeders['feriados_angola'] as $f) {
            $stmt->execute([
                ':nome'       => $f['nome'],
                ':data'       => sprintf('%d-%02d-%02d', $ano, $f['mes'], $f['dia']),
                ':tipo'       => $f['tipo'],
                ':meio_dia'   => $f['meio_dia'],
                ':recorrente' => $f['recorrente'],
            ]);
        }

        // Horário padrão (normal 8h/dia, 44h/semana)
        $pdo->exec("
            INSERT IGNORE INTO `horarios` (`nome`, `tipo`, `horas_dia`, `horas_semana`, `tolerancia_entrada_min`, `intervalo_min`)
            VALUES ('Horário Normal', 'normal', 8.00, 44.00, 10, 60)
        ");

        // Turnos do horário padrão: Segunda a Sexta 08:00–17:00
        $horarioId = $pdo->lastInsertId();
        if ($horarioId) {
            $stmtTurno = $pdo->prepare("
                INSERT INTO `horario_turnos` (`horario_id`, `dia_semana`, `hora_entrada`, `hora_saida`, `hora_inicio_intervalo`, `hora_fim_intervalo`, `dia_folga`)
                VALUES (:hid, :dia, :entrada, :saida, :inicio_int, :fim_int, :folga)
            ");
            // 1=Seg a 5=Sex
            for ($dia = 1; $dia <= 5; $dia++) {
                $stmtTurno->execute([
                    ':hid'       => $horarioId,
                    ':dia'       => $dia,
                    ':entrada'   => '08:00:00',
                    ':saida'     => '17:00:00',
                    ':inicio_int' => '12:00:00',
                    ':fim_int'   => '13:00:00',
                    ':folga'     => 0,
                ]);
            }
            // Sábado e Domingo = folga
            foreach ([0, 6] as $dia) {
                $stmtTurno->execute([
                    ':hid'       => $horarioId,
                    ':dia'       => $dia,
                    ':entrada'   => '08:00:00',
                    ':saida'     => '17:00:00',
                    ':inicio_int' => null,
                    ':fim_int'   => null,
                    ':folga'     => 1,
                ]);
            }
        }
    }

    /**
     * Resolve dados do tenant a partir do subdomínio (com cache em memória)
     */
    private static function resolveTenant(string $subdominio): ?array
    {
        static $cache = [];

        if (isset($cache[$subdominio])) {
            return $cache[$subdominio];
        }

        $stmt = self::master()->prepare("
            SELECT t.*, p.`nome` as plano_nome, p.`max_funcionarios`, p.`max_relogios`,
                   p.`permite_face_recog`, p.`permite_api`, p.`permite_geofencing`, p.`permite_relat_avanc`
            FROM `tenants` t
            JOIN `planos` p ON t.`plano_id` = p.`id`
            WHERE t.`subdominio` = :subdominio
            LIMIT 1
        ");
        $stmt->execute([':subdominio' => $subdominio]);

        $tenant = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $cache[$subdominio] = $tenant;

        return $tenant;
    }

    private static function createConnection(string $host, int $port, string $dbname, string $username, string $password): PDO
    {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone='+01:00'",
        ];

        try {
            return new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new RuntimeException("Falha na conexão à base de dados: " . $e->getMessage(), 500);
        }
    }

    private static function encryptDbPassword(string $password): string
    {
        $key = $_ENV['APP_KEY'];
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($password, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . '::' . $enc);
    }

    private static function decryptDbPassword(string $encrypted): string
    {
        $key  = $_ENV['APP_KEY'];
        $data = base64_decode($encrypted);
        [$iv, $enc] = explode('::', $data, 2);
        return openssl_decrypt($enc, 'AES-256-CBC', $key, 0, $iv);
    }

    private static function generateSecurePassword(): string
    {
        return bin2hex(random_bytes(24));
    }
}
