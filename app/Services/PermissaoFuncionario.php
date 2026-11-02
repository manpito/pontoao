<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Regra de permissões sobre registos de funcionários:
 * - o super_admin_tenant pode tudo;
 * - abaixo dele, ninguém age sobre os seus próprios registos;
 * - o supervisor só age sobre funcionários da sua equipa (funcionarios.supervisor_id).
 */
final class PermissaoFuncionario
{
    /**
     * Devolve null se a operação é permitida, ou a mensagem de erro se não for.
     */
    public static function verificar(PDO $db, ?object $user, int $funcionarioAlvoId): ?string
    {
        if ($user === null) {
            return 'Não autenticado.';
        }

        $perfil = (string) ($user->perfil ?? '');
        if ($perfil === 'super_admin_tenant') {
            return null;
        }

        $proprio = (int) ($user->funcionario_id ?? 0);
        if ($proprio > 0 && $funcionarioAlvoId === $proprio) {
            return 'Não pode efectuar esta operação sobre os seus próprios registos.';
        }

        if ($perfil === 'supervisor') {
            if ($proprio <= 0) {
                return 'Supervisor sem funcionário associado.';
            }
            $st = $db->prepare('SELECT id FROM funcionarios WHERE id = :fid AND supervisor_id = :sid LIMIT 1');
            $st->execute([':fid' => $funcionarioAlvoId, ':sid' => $proprio]);
            if (!$st->fetch()) {
                return 'Só pode efectuar esta operação sobre funcionários da sua equipa.';
            }
        }

        return null;
    }
}
