<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class PeriodoService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Calcula o período em vigor (início e fim) com base num mês base ou na data actual (hoje).
     * Reutiliza a lógica originalmente espalhada em ExportacaoController e RelatorioController.
     *
     * @param string|null $mes Base month in 'Y-m' format, or null to use today's date.
     * @return array ['inicio' => 'YYYY-MM-DD', 'fim' => 'YYYY-MM-DD']
     */
    public function getPeriodoActual(?string $mes = null): array
    {
        $stmtCfg = $this->pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('periodo_dia_inicio','periodo_dia_fim')");
        $config = [];
        while ($row = $stmtCfg->fetch(PDO::FETCH_ASSOC)) {
            $config[$row['chave']] = $row['valor'];
        }

        $diaInicio = (int) ($config['periodo_dia_inicio'] ?? 1);
        $diaFim = (int) ($config['periodo_dia_fim'] ?? 31);

        $hoje = $mes ? $mes . '-01' : date('Y-m-d');
        $ano = (int) date('Y', strtotime($hoje));
        $mesInt = (int) date('m', strtotime($hoje));

        if ($mes === null && $diaInicio > 1) {
            $diaHoje = (int) date('d', strtotime($hoje));
            if ($diaHoje < $diaInicio) {
                // Estamos no período que começou no mês passado e termina neste mês
                $mesAnterior = $mesInt === 1 ? 12 : $mesInt - 1;
                $anoAnterior = $mesInt === 1 ? $ano - 1 : $ano;

                $mesFim = $mesInt;
                $anoFim = $ano;
            } else {
                // Estamos no período que começou neste mês e termina no próximo
                $mesAnterior = $mesInt;
                $anoAnterior = $ano;

                $mesFim = $mesInt === 12 ? 1 : $mesInt + 1;
                $anoFim = $mesInt === 12 ? $ano + 1 : $ano;
            }

            $ultimoDia = cal_days_in_month(CAL_GREGORIAN, $mesAnterior, $anoAnterior);
            $diaReal = min($diaInicio, $ultimoDia);
            $dataInicio = sprintf('%04d-%02d-%02d', $anoAnterior, $mesAnterior, $diaReal);

            $ultimoDiaMesFim = cal_days_in_month(CAL_GREGORIAN, $mesFim, $anoFim);
            if ($diaFim >= $ultimoDiaMesFim || $diaFim === 31) {
                $dataFim = sprintf('%04d-%02d-%02d', $anoFim, $mesFim, $ultimoDiaMesFim);
            } else {
                $dataFim = sprintf('%04d-%02d-%02d', $anoFim, $mesFim, $diaFim);
            }
        } elseif ($diaInicio > 1) {
            // Logica usada para Exportacoes / Relatorios baseados apenas no mês passado
            $mesAnterior = $mesInt === 1 ? 12 : $mesInt - 1;
            $anoAnterior = $mesInt === 1 ? $ano - 1 : $ano;
            $ultimoDia = cal_days_in_month(CAL_GREGORIAN, $mesAnterior, $anoAnterior);
            $diaReal = min($diaInicio, $ultimoDia);
            $dataInicio = sprintf('%04d-%02d-%02d', $anoAnterior, $mesAnterior, $diaReal);

            $ultimoDiaMesFim = cal_days_in_month(CAL_GREGORIAN, $mesInt, $ano);
            if ($diaFim >= $ultimoDiaMesFim || $diaFim === 31) {
                $dataFim = sprintf('%04d-%02d-%02d', $ano, $mesInt, $ultimoDiaMesFim);
            } else {
                $dataFim = sprintf('%04d-%02d-%02d', $ano, $mesInt, $diaFim);
            }
        } else {
            $dataInicio = sprintf('%04d-%02d-%02d', $ano, $mesInt, 1);
            $ultimoDiaMesFim = cal_days_in_month(CAL_GREGORIAN, $mesInt, $ano);
            if ($diaFim >= $ultimoDiaMesFim || $diaFim === 31) {
                $dataFim = sprintf('%04d-%02d-%02d', $ano, $mesInt, $ultimoDiaMesFim);
            } else {
                $dataFim = sprintf('%04d-%02d-%02d', $ano, $mesInt, $diaFim);
            }
        }

        return ['inicio' => $dataInicio, 'fim' => $dataFim];
    }
}
