<?php

declare(strict_types=1);

use App\Services\PermissaoFuncionario;
use PHPUnit\Framework\TestCase;

final class PermissaoFuncionarioTest extends TestCase
{
    private function pdo(): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite indisponível');
        }
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE funcionarios (id INTEGER PRIMARY KEY, supervisor_id INTEGER NULL)');
        $db->exec('INSERT INTO funcionarios (id, supervisor_id) VALUES (4, NULL), (7, 4), (9, 6)');
        return $db;
    }

    private function user(string $perfil, ?int $funcionarioId): object
    {
        return (object) ['perfil' => $perfil, 'funcionario_id' => $funcionarioId, 'sub' => '1'];
    }

    public function testAdminPodeAgirSobreSiProprio(): void
    {
        $this->assertNull(PermissaoFuncionario::verificar($this->pdo(), $this->user('super_admin_tenant', 4), 4));
    }

    public function testRhManagerNaoAgeSobreSiProprio(): void
    {
        $this->assertNotNull(PermissaoFuncionario::verificar($this->pdo(), $this->user('rh_manager', 4), 4));
    }

    public function testRhManagerAgeSobreOutro(): void
    {
        $this->assertNull(PermissaoFuncionario::verificar($this->pdo(), $this->user('rh_manager', 4), 9));
    }

    public function testSupervisorAgeSobreEquipa(): void
    {
        $this->assertNull(PermissaoFuncionario::verificar($this->pdo(), $this->user('supervisor', 4), 7));
    }

    public function testSupervisorNaoAgeForaDaEquipa(): void
    {
        $this->assertNotNull(PermissaoFuncionario::verificar($this->pdo(), $this->user('supervisor', 4), 9));
    }

    public function testSupervisorNaoAgeSobreSiProprio(): void
    {
        $this->assertNotNull(PermissaoFuncionario::verificar($this->pdo(), $this->user('supervisor', 4), 4));
    }

    public function testSemUtilizadorNaoPermite(): void
    {
        $this->assertNotNull(PermissaoFuncionario::verificar($this->pdo(), null, 7));
    }
}
