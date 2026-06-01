<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;

class FeriadoService
{
    public function __construct(private PDO $pdo) {}

    /**
     * Verifica se uma data específica é feriado activo.
     */
    public function isFeriado(string $data): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM feriados WHERE data = :data LIMIT 1");
        $stmt->execute([':data' => $data]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Lista feriados num intervalo de datas.
     *
     * @return array<array{id:int, nome:string, data:string, tipo:string, meio_dia:int, recorrente:int}>
     */
    public function listarFeriadosEntre(string $dataInicio, string $dataFim): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, nome, data, tipo, meio_dia, recorrente, ano
             FROM feriados
             WHERE data BETWEEN :inicio AND :fim
             ORDER BY data ASC"
        );
        $stmt->execute([':inicio' => $dataInicio, ':fim' => $dataFim]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Calcula a data dos feriados móveis (Carnaval e Sexta-Feira Santa) para um ano.
     *
     * @return array{carnaval: string, sexta_santa: string} datas no formato YYYY-MM-DD
     */
    public static function calcularFeriadosMoveis(int $ano): array
    {
        if (!function_exists('easter_date')) {
            return self::calcularFeriadosMoveisManual($ano);
        }

        $timestamp = easter_date($ano);
        $domingoPascoa = (new DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));

        $carnaval = $domingoPascoa->modify('-47 days')->format('Y-m-d');
        $sextaSanta = $domingoPascoa->modify('-2 days')->format('Y-m-d');

        return [
            'carnaval' => $carnaval,
            'sexta_santa' => $sextaSanta,
        ];
    }

    /**
     * Algoritmo de Butcher-Meus-Gauss para cálculo da Páscoa (fallback se easter_date() não existir)
     */
    private static function calcularFeriadosMoveisManual(int $year): array
    {
        $a = $year % 19;
        $b = (int)($year / 100);
        $c = $year % 100;
        $d = (int)($b / 4);
        $e = $b % 4;
        $f = (int)(($b + 8) / 25);
        $g = (int)(($b - $f + 1) / 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = (int)($c / 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = (int)(($a + 11 * $h + 22 * $l) / 451);
        $month = (int)(($h + $l - 7 * $m + 114) / 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        $domingoPascoa = new DateTimeImmutable(sprintf('%d-%02d-%02d', $year, $month, $day));

        $carnaval = $domingoPascoa->modify('-47 days')->format('Y-m-d');
        $sextaSanta = $domingoPascoa->modify('-2 days')->format('Y-m-d');

        return [
            'carnaval' => $carnaval,
            'sexta_santa' => $sextaSanta,
        ];
    }

    /**
     * Pré-carrega feriados móveis para um ano específico.
     * Idempotente: usa INSERT IGNORE.
     * Os feriados fixos JÁ estão pré-populados na tabela e não são tocados aqui.
     *
     * @return int Número de feriados móveis inseridos (0, 1 ou 2)
     */
    public function preCarregarFeriadosMoveis(int $ano): int
    {
        $moveis = self::calcularFeriadosMoveis($ano);
        $inseridos = 0;

        $insertIgnore = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        $stmt = $this->pdo->prepare(
            "{$insertIgnore} INTO feriados (nome, data, tipo, meio_dia, recorrente, ano)
             VALUES (:nome, :data, 'nacional', 0, 0, :ano)"
        );

        // Carnaval
        $stmt->execute([
            ':nome' => 'Carnaval',
            ':data' => $moveis['carnaval'],
            ':ano' => $ano,
        ]);
        $inseridos += $stmt->rowCount();

        // Sexta-Feira Santa
        $stmt->execute([
            ':nome' => 'Sexta-Feira Santa',
            ':data' => $moveis['sexta_santa'],
            ':ano' => $ano,
        ]);
        $inseridos += $stmt->rowCount();

        return $inseridos;
    }
}
