<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Configuração do tenant: data a partir da qual só conta trabalho extraordinário aprovado
 * (Lei n.º 12/23, art. 184.º, n.º 3). Chave em configuracoes: horas_extra_aprovacao_obrigatoria_desde (AAAA-MM-DD).
 * Sem a chave, devolve null e o cálculo não muda.
 */
final class ConfigHorasExtra
{
    private static array $cache = [];

    public static function aprovacaoObrigatoriaDesde(PDO $db): ?string
    {
        $k = spl_object_id($db);
        if (array_key_exists($k, self::$cache)) {
            return self::$cache[$k];
        }
        $valor = null;
        try {
            $st = $db->query("SELECT valor FROM configuracoes WHERE chave = 'horas_extra_aprovacao_obrigatoria_desde'");
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if ($row && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $row['valor'])) {
                $valor = (string) $row['valor'];
            }
        } catch (\Throwable $e) {
            $valor = null;
        }
        self::$cache[$k] = $valor;
        return $valor;
    }
}
