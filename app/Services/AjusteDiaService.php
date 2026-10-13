<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;

class AjusteDiaService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Carrega os ajustes activos para um conjunto de funcionários num intervalo de datas.
     * Devolve um array indexado por funcionario_id, depois por data.
     *
     * Se a tabela `ajustes_dia` não existir, devolve um array vazio e regista aviso.
     *
     * @param array<int> $funcionarioIds
     * @param string $inicio (YYYY-MM-DD)
     * @param string $fim (YYYY-MM-DD)
     * @return array<int, array<string, array>>
     */
    public function carregarPorFuncionarios(array $funcionarioIds, string $inicio, string $fim): array
    {
        if (empty($funcionarioIds)) {
            return [];
        }

        $inIds = implode(',', array_map('intval', $funcionarioIds));

        try {
            // Utilizamos INNER JOIN com tipos_justificacao para obter o código
            $sql = "
                SELECT a.id, a.funcionario_id, a.data, a.lote_id, a.nota,
                       t.codigo AS tipo, t.id AS tipo_id,
                       t.nome AS tipo_nome, t.cor AS tipo_cor, t.comportamento AS tipo_comportamento
                FROM ajustes_dia a
                JOIN tipos_justificacao t ON a.tipo_justificacao_id = t.id
                WHERE a.funcionario_id IN ({$inIds})
                  AND a.data BETWEEN :inicio AND :fim
                  AND a.activo = 1
            ";

            $stmt = $this->db->prepare($sql);

            // Tratamos o caso da cor faltar na tabela tipos_justificacao de forma implícita.
            // Se falhar apenas por falta da coluna 'cor', fazemos query sem cor.
            try {
                $stmt->execute([':inicio' => $inicio, ':fim' => $fim]);
            } catch (PDOException $e) {
                 if ($e->getCode() == '42S22') { // coluna cor nao existe
                    $sql = "
                        SELECT a.id, a.funcionario_id, a.data, a.lote_id, a.nota,
                               t.codigo AS tipo, t.id AS tipo_id,
                               t.nome AS tipo_nome, NULL AS tipo_cor, t.comportamento AS tipo_comportamento
                        FROM ajustes_dia a
                        JOIN tipos_justificacao t ON a.tipo_justificacao_id = t.id
                        WHERE a.funcionario_id IN ({$inIds})
                          AND a.data BETWEEN :inicio AND :fim
                          AND a.activo = 1
                    ";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([':inicio' => $inicio, ':fim' => $fim]);
                 } else {
                     throw $e;
                 }
            }

            $ajustes = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $fId = (int) $row['funcionario_id'];
                $data = $row['data'];
                $ajustes[$fId][$data] = $row;
            }

            return $ajustes;

        } catch (PDOException $e) {
            // Tabela não encontrada
            if (in_array($e->getCode(), ['42S02', 'HY000'])) {
                // Check explicitly if HY000 is due to table not found (sqlite behavior in tests can vary)
                if (strpos($e->getMessage(), 'ajustes_dia') !== false) {
                     // Utiliza uma propriedade estática para logar só uma vez por request
                     static $logged = false;
                     if (!$logged) {
                         error_log("Aviso: tabela ajustes_dia não encontrada. Retornando vazio para compatibilidade.");
                         $logged = true;
                     }
                     return [];
                }
            }
            throw $e;
        }
    }
}
