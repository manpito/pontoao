<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class TipoJustificacaoService
{
    /**
     * @return array<string, string>
     */
    public static function getComportamentoMap(PDO $db): array
    {
        try {
            $stmt = $db->query("SELECT codigo, comportamento FROM tipos_justificacao");
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $map[$t['codigo']] = $t['comportamento'];
            }
            return $map;
        } catch (\Exception $e) {
            error_log("Erro ao carregar tipos_justificacao: " . $e->getMessage());
            return [];
        }
    }
}
