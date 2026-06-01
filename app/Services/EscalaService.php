<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;

class EscalaService
{
    public function __construct(private PDO $pdo) {}

    /**
     * Calcula o turno de um funcionário numa data específica.
     *
     * @return array{
     *   turno_id: int,
     *   turno_nome: string,
     *   tipo: 'trabalho'|'folga'|'compensatorio',
     *   hora_entrada: ?string,
     *   hora_saida: ?string,
     *   hora_inicio_intervalo: ?string,
     *   hora_fim_intervalo: ?string,
     *   horas_efectivas: ?float,
     *   atravessa_dia_civil: bool,
     *   classificacao_legal: string,
     *   escala_id: int,
     *   posicao_no_ciclo: int,
     *   substituicao: ?array
     * } | null   Retorna null se o funcionário não tem escala atribuída na data.
     */
    public function calcularTurnoEm(int $funcionarioId, string $data): ?array
    {
        $dt = new DateTimeImmutable($data);
        $dataStr = $dt->format('Y-m-d');

        // 1. Verificar se existe uma excepção onde o funcionário é substituto
        $stmt = $this->pdo->prepare("
            SELECT exc.*, t.nome as turno_nome, t.tipo as turno_tipo, t.hora_entrada, t.hora_saida,
                   t.hora_inicio_intervalo, t.hora_fim_intervalo, t.horas_efectivas,
                   t.atravessa_dia_civil, t.classificacao_legal,
                   fe.escala_id
            FROM escala_excepcoes exc
            JOIN turnos t ON t.id = exc.turno_id
            LEFT JOIN funcionario_escala fe ON fe.funcionario_id = exc.funcionario_ausente_id
                 AND exc.data BETWEEN fe.data_inicio AND COALESCE(fe.data_fim, '9999-12-31')
            WHERE exc.data = :data AND exc.funcionario_substituto_id = :fid
            LIMIT 1
        ");
        $stmt->execute(['data' => $dataStr, 'fid' => $funcionarioId]);
        $excepcaoSubstituto = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($excepcaoSubstituto) {
            return [
                'turno_id' => (int) $excepcaoSubstituto['turno_id'],
                'turno_nome' => $excepcaoSubstituto['turno_nome'],
                'tipo' => $excepcaoSubstituto['turno_tipo'],
                'hora_entrada' => $excepcaoSubstituto['hora_entrada'],
                'hora_saida' => $excepcaoSubstituto['hora_saida'],
                'hora_inicio_intervalo' => $excepcaoSubstituto['hora_inicio_intervalo'],
                'hora_fim_intervalo' => $excepcaoSubstituto['hora_fim_intervalo'],
                'horas_efectivas' => $excepcaoSubstituto['horas_efectivas'] !== null ? (float) $excepcaoSubstituto['horas_efectivas'] : null,
                'atravessa_dia_civil' => (bool) $excepcaoSubstituto['atravessa_dia_civil'],
                'classificacao_legal' => $excepcaoSubstituto['classificacao_legal'],
                'escala_id' => $excepcaoSubstituto['escala_id'] !== null ? (int) $excepcaoSubstituto['escala_id'] : 0,
                'posicao_no_ciclo' => 0, // Não aplicável em substituição directa
                'substituicao' => [
                    'tipo' => 'substituto',
                    'funcionario_ausente_id' => (int) $excepcaoSubstituto['funcionario_ausente_id'],
                    'motivo' => $excepcaoSubstituto['motivo']
                ]
            ];
        }

        // 2. Consultar funcionario_escala para a escala activa do funcionário na data
        $stmt = $this->pdo->prepare("
            SELECT fe.*, e.tamanho_ciclo
            FROM funcionario_escala fe
            JOIN escalas e ON e.id = fe.escala_id
            WHERE fe.funcionario_id = :fid
              AND :data BETWEEN fe.data_inicio AND COALESCE(fe.data_fim, '9999-12-31')
            LIMIT 1
        ");
        $stmt->execute(['fid' => $funcionarioId, 'data' => $dataStr]);
        $fe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fe) {
            return null;
        }

        $escalaId = (int) $fe['escala_id'];
        $dataInicio = new DateTimeImmutable($fe['data_inicio']);
        $posicaoInicial = (int) $fe['posicao_inicial'];
        $tamanhoCiclo = (int) $fe['tamanho_ciclo'];

        // 3. Calcular dias decorridos e posicao_no_ciclo
        $diasDecorridos = $dt->diff($dataInicio)->days;
        if ($dataInicio > $dt) {
            return null; // Caso a data seja anterior ao início da escala (teoricamente filtrado pela query)
        }

        $posicaoNoCiclo = (($posicaoInicial - 1 + $diasDecorridos) % $tamanhoCiclo) + 1;

        // 4. Verificar se existe uma excepção onde o funcionário está ausente
        $stmt = $this->pdo->prepare("
            SELECT exc.*
            FROM escala_excepcoes exc
            WHERE exc.data = :data AND exc.funcionario_ausente_id = :fid
            LIMIT 1
        ");
        $stmt->execute(['data' => $dataStr, 'fid' => $funcionarioId]);
        $excepcaoAusente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($excepcaoAusente) {
            // Se está ausente, retorna o turno original mas com flag de ausência.
            // Para efeitos de cálculo de ponto, costuma-se considerar como 'folga' virtual ou manter o turno com flag.
            // O requisito diz: "Se está como ausente, retorna turno.tipo = 'folga' virtualmente (ou retorna o turno mas com flag de ausência)."
            // Vou optar por retornar o turno original mas com a flag de substituição/ausência.
        }

        // 5. Consultar escala_turnos e turnos para obter o detalhe do turno
        $stmt = $this->pdo->prepare("
            SELECT et.turno_id, t.nome as turno_nome, t.tipo as turno_tipo, t.hora_entrada, t.hora_saida,
                   t.hora_inicio_intervalo, t.hora_fim_intervalo, t.horas_efectivas,
                   t.atravessa_dia_civil, t.classificacao_legal
            FROM escala_turnos et
            JOIN turnos t ON t.id = et.turno_id
            WHERE et.escala_id = :eid AND et.posicao = :pos
            LIMIT 1
        ");
        $stmt->execute(['eid' => $escalaId, 'pos' => $posicaoNoCiclo]);
        $turno = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$turno) {
            return null;
        }

        $tipoTurno = $turno['turno_tipo'];
        $substituicao = null;
        if ($excepcaoAusente) {
            $tipoTurno = 'folga'; // Virtualmente folga conforme sugestão do requisito
            $substituicao = [
                'tipo' => 'ausente',
                'funcionario_substituto_id' => $excepcaoAusente['funcionario_substituto_id'] ? (int) $excepcaoAusente['funcionario_substituto_id'] : null,
                'motivo' => $excepcaoAusente['motivo']
            ];
        }

        return [
            'turno_id' => (int) $turno['turno_id'],
            'turno_nome' => $turno['turno_nome'],
            'tipo' => $tipoTurno,
            'hora_entrada' => $turno['hora_entrada'],
            'hora_saida' => $turno['hora_saida'],
            'hora_inicio_intervalo' => $turno['hora_inicio_intervalo'],
            'hora_fim_intervalo' => $turno['hora_fim_intervalo'],
            'horas_efectivas' => $turno['horas_efectivas'] !== null ? (float) $turno['horas_efectivas'] : null,
            'atravessa_dia_civil' => (bool) $turno['atravessa_dia_civil'],
            'classificacao_legal' => $turno['classificacao_legal'],
            'escala_id' => $escalaId,
            'posicao_no_ciclo' => $posicaoNoCiclo,
            'substituicao' => $substituicao
        ];
    }
}
