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

    /**
     * @return array<string, array> Mapa indexado pelo codigo contendo id, codigo, nome, comportamento, e cor.
     */
    public static function getTiposMap(PDO $db): array
    {
        try {
            // Tenta obter a cor, mas suporta a ausência da coluna
            $hasCor = true;
            try {
                $db->query("SELECT cor FROM tipos_justificacao LIMIT 1");
            } catch (\PDOException $e) {
                if (in_array($e->getCode(), ['42S22', '42S02'])) {
                    $hasCor = false;
                } else {
                    throw $e;
                }
            }

            $colCor = $hasCor ? ", cor" : "";
            $stmt = $db->query("SELECT id, codigo, nome, comportamento{$colCor} FROM tipos_justificacao");
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $map[$t['codigo']] = [
                    'id' => (int) $t['id'],
                    'codigo' => $t['codigo'],
                    'nome' => $t['nome'],
                    'comportamento' => $t['comportamento'],
                    'cor' => $hasCor ? $t['cor'] : null
                ];
            }
            return $map;
        } catch (\Exception $e) {
            error_log("Erro ao carregar tipos_justificacao (getTiposMap): " . $e->getMessage());
            return [];
        }
    }
}
