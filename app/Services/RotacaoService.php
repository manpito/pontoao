<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;

class RotacaoService
{
    public function __construct(private PDO $pdo) {}

    /**
     * Calcula a fase em que um funcionário está numa data específica.
     *
     * @return array{
     *   fase: 'trabalho'|'folga',
     *   horario_esperado: array{
     *     hora_entrada: ?string,
     *     hora_saida: ?string,
     *     hora_inicio_intervalo: ?string,
     *     hora_fim_intervalo: ?string,
     *     horas_dia: ?float
     *   },
     *   rotacao_id: int,
     *   fase_id: int
     * } | null   Retorna null se funcionário não tem rotação activa nessa data.
     */
    public function calcularFaseEm(int $funcionarioId, string $data): ?array
    {
        $dt = new DateTimeImmutable($data);
        $dataStr = $dt->format('Y-m-d');

        // 1. Encontrar rotação activa do funcionário na data
        $stmt = $this->pdo->prepare("
            SELECT fr.*, r.ignora_fds, r.ignora_feriados
            FROM funcionario_rotacao fr
            JOIN rotacoes r ON r.id = fr.rotacao_id
            WHERE fr.funcionario_id = :fid
              AND :data BETWEEN fr.data_inicio AND COALESCE(fr.data_fim, '9999-12-31')
            LIMIT 1
        ");
        $stmt->execute(['fid' => $funcionarioId, 'data' => $dataStr]);
        $fr = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fr) {
            return null;
        }

        $rotacaoId = (int) $fr['rotacao_id'];
        $dataInicio = new DateTimeImmutable($fr['data_inicio']);
        $faseInicial = (int) $fr['fase_inicial'];
        $ignoraFds = (bool) $fr['ignora_fds'];
        // $ignoraFeriados = (bool) $fr['ignora_feriados']; // TODO: integrar com tabela de feriados futuramente

        // 2. Buscar todas as fases da rotação
        $stmt = $this->pdo->prepare("
            SELECT * FROM rotacao_fases
            WHERE rotacao_id = :rid
            ORDER BY ordem ASC
        ");
        $stmt->execute(['rid' => $rotacaoId]);
        $fases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($fases)) {
            return null;
        }

        // 3. Calcular total de dias do ciclo
        $totalDiasCiclo = 0;
        foreach ($fases as $f) {
            $totalDiasCiclo += (int) $f['duracao_dias'];
        }

        if ($totalDiasCiclo === 0) {
            return null;
        }

        // 4. Calcular dias decorridos desde data_inicio até data, considerando ignora_fds
        $diasDecorridosAjustado = 0;
        $current = $dataInicio;

        // Se a data pedida for anterior à data de início, já teria retornado null pela query,
        // mas por segurança garantimos que current <= dt
        if ($current > $dt) {
            return null;
        }

        while ($current < $dt) {
            $next = $current->modify('+1 day');
            $nextIsFds = (int)$next->format('N') >= 6; // 6 = Sábado, 7 = Domingo

            if ($ignoraFds && $nextIsFds) {
                // Não conta para avanço de fase ao entrar num fim-de-semana
            } else {
                $diasDecorridosAjustado++;
            }
            $current = $next;
        }

        // 5. Aplicar offset de fase_inicial - 1
        // Precisamos descobrir em que dia do ciclo começa a fase_inicial
        $offsetInicial = 0;
        for ($i = 0; $i < ($faseInicial - 1); $i++) {
            $offsetInicial += (int) $fases[$i]['duracao_dias'];
        }

        $posicao = ($diasDecorridosAjustado + $offsetInicial) % $totalDiasCiclo;

        // 6. Identificar fase actual em rotacao_fases
        $faseActual = null;
        $acc = 0;
        foreach ($fases as $f) {
            $acc += (int) $f['duracao_dias'];
            if ($posicao < $acc) {
                $faseActual = $f;
                break;
            }
        }

        if (!$faseActual) {
            return null;
        }

        // 7. Retornar dados
        return [
            'fase' => $faseActual['tipo_fase'], // 'trabalho' ou 'folga'
            'horario_esperado' => [
                'hora_entrada'          => $faseActual['hora_entrada'],
                'hora_saida'            => $faseActual['hora_saida'],
                'hora_inicio_intervalo' => $faseActual['hora_inicio_intervalo'],
                'hora_fim_intervalo'    => $faseActual['hora_fim_intervalo'],
                'horas_dia'             => $faseActual['horas_dia'] !== null ? (float) $faseActual['horas_dia'] : null,
            ],
            'rotacao_id' => $rotacaoId,
            'fase_id'    => (int) $faseActual['id']
        ];
    }
}
