<?php

namespace App\Services;

use PDO;

class ZkComandoService
{
    public function __construct(private PDO $pdo) {}

    public function enfileirarCriacaoUtilizador(int $funcionarioId): void
    {
        $func = $this->pdo->prepare("SELECT numero_funcionario, nome_completo, pin_marcacao FROM funcionarios WHERE id = :id LIMIT 1");
        $func->execute([':id' => $funcionarioId]);
        $f = $func->fetch(PDO::FETCH_ASSOC);
        if (!$f) return;

        $relogios = $this->pdo->query("SELECT id FROM relogios WHERE activo = 1")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($relogios as $relogioId) {
            $tab = "\t";
            $payload = "DATA UPDATE USERINFO Pin={$f['numero_funcionario']}{$tab}Name={$f['nome_completo']}{$tab}Pri=0{$tab}Passwd=" . ($f['pin_marcacao'] ?? '') . "{$tab}Card=0{$tab}Grp=1{$tab}TZ=0000000100000000{$tab}Verify=0{$tab}ViceCard=0";
            $this->inserirComando($relogioId, 'criar_utilizador', $payload, $funcionarioId);
        }
    }

    public function enfileirarApagarUtilizador(string $numeroFuncionario): void
    {
        $relogios = $this->pdo->query("SELECT id FROM relogios WHERE activo = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($relogios as $relogioId) {
            $payload = "DATA DELETE USERINFO Pin={$numeroFuncionario}";
            $this->inserirComando($relogioId, 'apagar_utilizador', $payload, null);
        }
    }

    public function obterProximoComando(int $relogioId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, payload
            FROM comandos_terminal
            WHERE relogio_id = :rid AND estado = 'pendente' AND tentativas < 5
            ORDER BY criado_em ASC
            LIMIT 1
        ");
        $stmt->execute([':rid' => $relogioId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function marcarEnviado(int $comandoId): void
    {
        $this->pdo->prepare("
            UPDATE comandos_terminal
            SET estado = 'enviado', enviado_em = NOW(), tentativas = tentativas + 1
            WHERE id = :id
        ")->execute([':id' => $comandoId]);
    }

    public function marcarConfirmado(int $comandoId): void
    {
        $this->pdo->prepare("
            UPDATE comandos_terminal
            SET estado = 'confirmado', confirmado_em = NOW()
            WHERE id = :id
        ")->execute([':id' => $comandoId]);
    }

    public function marcarErro(int $comandoId, string $detalhe): void
    {
        $this->pdo->prepare("
            UPDATE comandos_terminal
            SET estado = 'erro', erro_detalhe = :detalhe, tentativas = tentativas + 1
            WHERE id = :id
        ")->execute([':id' => $comandoId, ':detalhe' => $detalhe]);
    }

    private function inserirComando(int $relogioId, string $tipo, string $payload, ?int $funcionarioId): void
    {
        $this->pdo->prepare("
            INSERT INTO comandos_terminal (relogio_id, tipo, payload, estado, funcionario_id)
            VALUES (:rid, :tipo, :payload, 'pendente', :fid)
        ")->execute([':rid' => $relogioId, ':tipo' => $tipo, ':payload' => $payload, ':fid' => $funcionarioId]);
    }
}
