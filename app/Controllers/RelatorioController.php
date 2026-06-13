<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * RelatorioController — Relatórios de assiduidade e horas trabalhadas
 *
 * Lógica conforme LGT Lei 7/15:
 * - Art.º 96.º: limite de 8h/dia e 44h/semana
 * - Art.º 100.º: intervalo mínimo de 30min obrigatório
 * - Art.º 215.º: 22 dias úteis de férias/ano
 * - Horas extra = horas efectivas - horas esperadas (quando positivo)
 */
class RelatorioController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    private function getTenantInfo(): array
    {
        $sub  = TenantResolver::resolve();
        $stmt = Database::master()->prepare(
            "SELECT nome_empresa, nif FROM tenants WHERE subdominio = :sub LIMIT 1"
        );
        $stmt->execute([':sub' => $sub]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'nome_empresa' => $row['nome_empresa'] ?? '',
            'nif'          => $row['nif'] ?? '',
        ];
    }

    /**
     * GET /api/relatorios/assiduidade
     * Params: data_inicio, data_fim, funcionario_id (opcional), departamento_id (opcional)
     *
     * Devolve por funcionário e por dia:
     * - presente / ausente / feriado / fim_semana
     * - tipo de ausência (justificada / injustificada)
     * - marcações do dia
     */
    public function marcacoesDiarias(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenantInfo = $this->getTenantInfo();
        $user   = $request->getAttribute('auth_user');
        $perfil = $request->getAttribute('auth_perfil');
        $funcId = (int) $args['funcionario_id'];

        // RBAC: admin e rh vêem qualquer funcionário. Supervisor vê apenas funcionários da sua equipa. Funcionário vê apenas o seu próprio relatório.
        if ($perfil === 'funcionario' && $user->funcionario_id != $funcId) {
            return $this->json($response, 403, ['erro' => true, 'mensagem' => 'Sem permissão para ver este relatório.']);
        }

        $params     = $request->getQueryParams();
        $dataInicio = $params['inicio'] ?? date('Y-m-01');
        $dataFim    = $params['fim']    ?? date('Y-m-t');
        $formato    = $params['formato'] ?? 'json';

        $db = $this->db();

        // 1. Dados do funcionário
        $stmtF = $db->prepare("
            SELECT f.id, f.nome_completo, f.numero_funcionario, f.data_admissao,
                   d.nome AS departamento, c.nome AS cargo, f.supervisor_id
            FROM funcionarios f
            LEFT JOIN departamentos d ON f.departamento_id = d.id
            LEFT JOIN cargos c ON f.cargo_id = c.id
            WHERE f.id = :id
        ");
        $stmtF->execute([':id' => $funcId]);
        $func = $stmtF->fetch(PDO::FETCH_ASSOC);

        if (!$func) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Funcionário não encontrado.']);
        }

        if ($perfil === 'supervisor' && $func['supervisor_id'] != $user->funcionario_id && $func['id'] != $user->funcionario_id) {
            return $this->json($response, 403, ['erro' => true, 'mensagem' => 'Sem permissão para ver este relatório (não pertence à sua equipa).']);
        }

        // 2. Feriados
        $feriados = $this->getFeriados($db, $dataInicio, $dataFim);

        // 3. Marcações com origem e auditoria
        $fimQuery = $dataFim . ' 23:59:59';
        if ($this->periodoTemTurnoNocturno($db, $funcId, $dataInicio, $dataFim)) {
            $fimQuery = date('Y-m-d', strtotime($dataFim . ' +1 day')) . ' 12:00:00';
        }

        $stmtM = $db->prepare("
            SELECT m.id, m.tipo, m.data_hora, m.origem,
                   m.editada, m.data_hora_original, m.motivo_edicao, m.data_edicao,
                   u.nome AS editada_por_nome
            FROM marcacoes m
            LEFT JOIN utilizadores u ON m.editada_por = u.id
            WHERE m.funcionario_id = :fid
              AND m.data_hora BETWEEN :ini AND :fim
            ORDER BY m.data_hora ASC
        ");
        $stmtM->execute([
            ':fid' => $funcId,
            ':ini' => $dataInicio . ' 00:00:00',
            ':fim' => $fimQuery
        ]);
        $marcacoesRaw = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        $escalaService = new \App\Services\EscalaService($db);

        // Carregar pedidos de horas extra aprovados do período
        $stmtPHE = $db->prepare("
            SELECT data, minutos FROM pedidos_horas_extra
            WHERE funcionario_id = :fid
              AND estado = 'aprovado'
              AND data BETWEEN :ini AND :fim
        ");
        $stmtPHE->execute([':fid' => $funcId, ':ini' => $dataInicio, ':fim' => $dataFim]);
        $horasExtraAprovadas = [];
        foreach ($stmtPHE->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $horasExtraAprovadas[$r['data']] = (int) $r['minutos'];
        }

        $regimeEscala = 'normal';
        $stmtReg = $db->prepare("
        SELECT e.regime FROM escalas e
        JOIN funcionario_escala fe ON fe.escala_id = e.id
        WHERE fe.funcionario_id = :fid AND (fe.data_fim IS NULL OR fe.data_fim >= CURDATE())
        LIMIT 1
        ");
        $stmtReg->execute([':fid' => $funcId]);
        $rowReg = $stmtReg->fetch(PDO::FETCH_ASSOC);
            if ($rowReg) $regimeEscala = $rowReg['regime'];

        // Agrupar por dia
        $marcPorDia = [];
        foreach ($marcacoesRaw as $m) {
            $dia = substr($m['data_hora'], 0, 10);
            $hora = (int) substr($m['data_hora'], 11, 2);
            if ($m['tipo'] === 'saida' && $hora < 12) {
                $diaAnterior = date('Y-m-d', strtotime($dia . ' -1 day'));
                $turnoAnterior = $escalaService->calcularTurnoEm($funcId, $diaAnterior);
                if ($turnoAnterior && $turnoAnterior['atravessa_dia_civil']) {
                    $dia = $diaAnterior;
                }
            }
            $marcPorDia[$dia][] = $m;
        }

        // 4. Processar dias
        $dias = [];
        $atual = strtotime($dataInicio);
        $fim   = strtotime($dataFim);
        $nomesDias = [1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado', 7 => 'Domingo'];

        while ($atual <= $fim) {
            $dataStr   = date('Y-m-d', $atual);
            $diaSemana = (int) date('N', $atual);

            $mDia = $marcPorDia[$dataStr] ?? [];
            $marcacoesFormatadas = [];

            $primeiraEntrada = null;
            $ultimaSaida = null;
            $entradaTs = null;
            $minutosTotais = 0;
            $intervaloInicio = null;

            $turno = $escalaService->calcularTurnoEm($funcId, $dataStr);

            foreach ($mDia as $m) {
                $ts = strtotime($m['data_hora']);
                $hora = date('H:i', $ts);
                $tipoMapeado = match($m['tipo']) {
                    'entrada' => 'ENTRADA',
                    'saida' => 'SAÍDA',
                    'inicio_intervalo' => 'INI_INTERVALO',
                    'fim_intervalo' => 'FIM_INTERVALO',
                    default => strtoupper($m['tipo'])
                };

                $marcacoesFormatadas[] = [
                    'id'            => (int) $m['id'],
                    'hora'          => $hora,
                    'hora_original' => $m['data_hora_original'] ? date('H:i', strtotime($m['data_hora_original'])) : null,
                    'tipo'          => $tipoMapeado,
                    'origem'        => $m['origem'],
                    'editada'       => (bool) $m['editada'],
                    'motivo_edicao' => $m['motivo_edicao'],
                    'data_edicao'   => $m['data_edicao'] ? date('d/m/Y H:i', strtotime($m['data_edicao'])) : null,
                    'editada_por'   => $m['editada_por_nome'],
                ];

                if ($m['tipo'] === 'entrada') {
                    if ($primeiraEntrada === null) $primeiraEntrada = $hora;
                    $entradaTs = $ts;
                } elseif ($m['tipo'] === 'saida') {
                    $ultimaSaida = $hora;
                    if ($entradaTs) {
                        $diff = $ts - $entradaTs;
                        if ($turno && $turno['atravessa_dia_civil'] && $diff < 0) {
                             $diff += 86400;
                        }
                        $minutosTotais += (int)round($diff/60);
                        $entradaTs = null;
                    }
                } elseif ($m['tipo'] === 'inicio_intervalo') {
                    $intervaloInicio = $ts;
                } elseif ($m['tipo'] === 'fim_intervalo') {
                    if ($intervaloInicio) {
                        $diffInt = $ts - $intervaloInicio;
                        if ($turno && $turno['atravessa_dia_civil'] && $diffInt < 0) {
                            $diffInt += 86400;
                        }
                        $minutosTotais -= (int)round($diffInt/60);
                        $intervaloInicio = null;
                    }
                }
            }

            $minutosEsperados = 0;
            if ($turno && $turno['tipo'] !== 'folga' && $turno['horas_efectivas']) {
                $minutosEsperados = (int) round((float)$turno['horas_efectivas'] * 60);
            }

            $tipoDia = 'util';
            if (isset($feriados[$dataStr])) {
                $tipoDia = 'feriado';
            } elseif ($diaSemana === 6) {
                $tipoDia = 'sabado';
            } elseif ($diaSemana === 7) {
                $tipoDia = 'domingo';
            }

            $minutosExtra = 0;
            $minutosExtraExtraordinario = 0;
            if ($minutosTotais > 0) {
                if ($tipoDia === 'util') {
                    $minutosExtra = max(0, $minutosTotais - $minutosEsperados);
                } elseif (in_array($tipoDia, ['sabado', 'domingo', 'feriado'])) {
                    if ($regimeEscala === 'turnos') {
                        if ($turno && $turno['tipo'] !== 'folga') {
                            // regime de turnos: fins de semana com turno são dias normais
                            $minutosExtra = max(0, $minutosTotais - $minutosEsperados);
                        } else {
                            // regime de turnos: trabalhou num dia de folga = extraordinário
                            $minutosExtraExtraordinario = $minutosTotais;
                        }
                    } else {
                        // regime normal: fins de semana são sempre extraordinário
                        $minutosExtraExtraordinario = $minutosTotais;
                    }
                }
            }

            // Cálculo de atrasos e saídas antecipadas
            $atrasoMinutos = 0;
            $saidaAntecipadaMinutos = 0;

            if ($turno && $turno['tipo'] !== 'folga') {
                if ($turno['hora_entrada'] && $primeiraEntrada) {
                    [$hP, $mP] = explode(':', $turno['hora_entrada']);
                    $minPrevisto = (int)$hP * 60 + (int)$mP;
                    [$hR, $mR] = explode(':', $primeiraEntrada);
                    $minReal = (int)$hR * 60 + (int)$mR;
                    $tolerancia = $turno['tolerancia_entrada_min'] ?? 10;

                    $diff = $minReal - $minPrevisto;
                    if ($diff > $tolerancia) {
                        $atrasoMinutos = $diff - $tolerancia;
                    }
                }

                if ($turno['hora_saida'] && $ultimaSaida) {
                    $horaCorteSaida = substr($turno['hora_saida'], 0, 5);

                    // Lógica de arredondamento automático de horas extra não aprovadas
                    if ($ultimaSaida > $horaCorteSaida) {
                        $tsCorte = strtotime($dataStr . ' ' . $horaCorteSaida . ($turno['atravessa_dia_civil'] ? ' +1 day' : ''));
                        $tsReal  = strtotime($dataStr . ' ' . $ultimaSaida . ($turno['atravessa_dia_civil'] ? ' +1 day' : ''));
                        $minutosExtraReais = (int) round(($tsReal - $tsCorte) / 60);
                        $minutosAprovados = $horasExtraAprovadas[$dataStr] ?? 0;

                        if ($minutosExtraReais > $minutosAprovados) {
                            $novaHoraSaida = date('H:i', strtotime($horaCorteSaida . " +{$minutosAprovados} minutes"));

                            // Actualizar marcação de saída na DB (a última saída do dia)
                            $db->prepare("
                                UPDATE marcacoes SET data_hora = :nova, editada = 1,
                                    motivo_edicao = 'Arredondamento automático - horas extra não aprovadas',
                                    data_edicao = NOW()
                                WHERE funcionario_id = :fid AND tipo = 'saida'
                                  AND DATE(data_hora) = :data
                                  AND data_hora = (SELECT max_dh FROM (SELECT MAX(data_hora) as max_dh FROM marcacoes WHERE funcionario_id = :fid2 AND tipo = 'saida' AND DATE(data_hora) = :data2) t)
                            ")->execute([
                                ':nova'  => ($turno['atravessa_dia_civil'] && $novaHoraSaida < '12:00' ? date('Y-m-d', strtotime($dataStr . ' +1 day')) : $dataStr) . ' ' . $novaHoraSaida . ':00',
                                ':fid'   => $funcId,
                                ':data'  => $turno['atravessa_dia_civil'] && $ultimaSaida < '12:00' ? date('Y-m-d', strtotime($dataStr . ' +1 day')) : $dataStr,
                                ':fid2'  => $funcId,
                                ':data2' => $turno['atravessa_dia_civil'] && $ultimaSaida < '12:00' ? date('Y-m-d', strtotime($dataStr . ' +1 day')) : $dataStr
                            ]);

                            $ultimaSaida = $novaHoraSaida;
                            // Recalcular minutos totais do dia para o relatório
                            $minutosTotais -= ($minutosExtraReais - $minutosAprovados);
                        }
                    }

                    if ($turno['atravessa_dia_civil']) {
                        $tsPrevistoSaida = strtotime($dataStr . ' ' . $turno['hora_saida'] . ' +1 day');
                        $tsRealSaida = strtotime($dataStr . ' ' . $ultimaSaida . ' +1 day');
                        if ($tsPrevistoSaida - $tsRealSaida > 0) {
                            $saidaAntecipadaMinutos = (int) round(($tsPrevistoSaida - $tsRealSaida) / 60);
                        }
                    } else {
                        [$hP, $mP] = explode(':', $turno['hora_saida']);
                        $minPrevisto = (int)$hP * 60 + (int)$mP;
                        [$hR, $mR] = explode(':', $ultimaSaida);
                        $minReal = (int)$hR * 60 + (int)$mR;

                        if ($minPrevisto - $minReal > 0) {
                            $saidaAntecipadaMinutos = $minPrevisto - $minReal;
                        }
                    }
                }
            }

            $dias[] = [
                'data' => $dataStr,
                'dia_semana' => $nomesDias[$diaSemana],
                'marcacoes' => $marcacoesFormatadas,
                'resumo' => [
                    'tipo_dia'                  => $tipoDia,
                    'hora_prevista_entrada'     => $turno ? substr($turno['hora_entrada'] ?? '', 0, 5) : null,
                    'hora_prevista_saida'       => $turno ? substr($turno['hora_saida'] ?? '', 0, 5) : null,
                    'primeira_entrada'          => $primeiraEntrada,
                    'ultima_saida'              => $ultimaSaida,
                    'atraso_minutos'            => $atrasoMinutos,
                    'saida_antecipada_minutos'  => $saidaAntecipadaMinutos,
                    'total_horas'               => round($minutosTotais/60, 2),
                    'minutos_esperados'              => $minutosEsperados,
                    'minutos_extra'                  => $minutosExtra,
                    'minutos_extra_extraordinario'   => $minutosExtraExtraordinario,
                ]
            ];

            $atual = strtotime('+1 day', $atual);
        }

        $dados = [
            'empresa' => ['nome' => $tenantInfo['nome_empresa'], 'nif' => $tenantInfo['nif']],
            'funcionario' => [
                'id' => (int) $func['id'],
                'nome' => $func['nome_completo'],
                'numero' => $func['numero_funcionario'],
                'departamento' => $func['departamento'],
                'cargo' => $func['cargo']
            ],
            'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim],
            'dias' => $dias
        ];

        if ($formato === 'xlsx') {
            return $this->exportarExcel($dados, 'marcacoes_diarias', "relatorio_marcacoes_{$func['numero_funcionario']}", $response);
        }

        return $this->json($response, 200, $dados);
    }

    public function individual(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenantInfo = $this->getTenantInfo();
        $user   = $request->getAttribute('auth_user');
        $perfil = $request->getAttribute('auth_perfil');
        $funcId = (int) $args['funcionario_id'];

        // RBAC: admin e rh vêem qualquer funcionário. Supervisor vê apenas funcionários da sua equipa. Funcionário vê apenas o seu próprio relatório.
        if ($perfil === 'funcionario' && $user->funcionario_id != $funcId) {
            return $this->json($response, 403, ['erro' => true, 'mensagem' => 'Sem permissão para ver este relatório.']);
        }

        $params     = $request->getQueryParams();
        $dataInicio = $params['inicio'] ?? date('Y-m-01');
        $dataFim    = $params['fim']    ?? date('Y-m-t');
        $formato    = $params['formato'] ?? 'html';

        $db = $this->db();

        // 1. Dados do funcionário
        $stmtF = $db->prepare("
            SELECT f.id, f.nome_completo, f.numero_funcionario, f.data_admissao,
                   d.nome AS departamento, c.nome AS cargo,
                   h.horas_dia AS horas_esperadas_dia,
                   h.tolerancia_entrada_min,
                   f.supervisor_id
            FROM funcionarios f
            LEFT JOIN departamentos d ON f.departamento_id = d.id
            LEFT JOIN cargos c ON f.cargo_id = c.id
            LEFT JOIN funcionario_horario fh ON fh.funcionario_id = f.id AND fh.data_fim IS NULL
            LEFT JOIN horarios h ON fh.horario_id = h.id
            WHERE f.id = :id
        ");
        $stmtF->execute([':id' => $funcId]);
        $func = $stmtF->fetch(PDO::FETCH_ASSOC);

        if (!$func) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Funcionário não encontrado.']);
        }

        if ($perfil === 'supervisor' && $func['supervisor_id'] != $user->funcionario_id && $func['id'] != $user->funcionario_id) {
            return $this->json($response, 403, ['erro' => true, 'mensagem' => 'Sem permissão para ver este relatório (não pertence à sua equipa).']);
        }

        // 2. Feriados
        $feriados = $this->getFeriados($db, $dataInicio, $dataFim);

        // 3. Marcações
        $stmtM = $db->prepare("
            SELECT tipo, data_hora
            FROM marcacoes
            WHERE funcionario_id = :fid
              AND data_hora BETWEEN :ini AND :fim
            ORDER BY data_hora ASC
        ");
        $stmtM->execute([
            ':fid' => $funcId,
            ':ini' => $dataInicio . ' 00:00:00',
            ':fim' => $dataFim    . ' 23:59:59'
        ]);
        $marcacoes = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        // 4. Marcações em falta
        $stmtMF = $db->prepare("
            SELECT data, nota_classificacao, estado
            FROM marcacoes_em_falta
            WHERE funcionario_id = :fid
              AND data BETWEEN :ini AND :fim
        ");
        $stmtMF->execute([':fid' => $funcId, ':ini' => $dataInicio, ':fim' => $dataFim]);
        $mfList = $stmtMF->fetchAll(PDO::FETCH_ASSOC);

        // 5. Justificações
        $stmtJ = $db->prepare("
            SELECT data_inicio, data_fim, tipo, estado
            FROM justificacoes
            WHERE funcionario_id = :fid
              AND data_inicio <= :fim AND data_fim >= :ini
              AND estado = 'aprovada'
        ");
        $stmtJ->execute([':fid' => $funcId, ':ini' => $dataInicio, ':fim' => $dataFim]);
        $justificacoes = $stmtJ->fetchAll(PDO::FETCH_ASSOC);

        // Agrupar marcações por dia
        $marcPorDia = [];
        foreach ($marcacoes as $m) {
            $dia = substr($m['data_hora'], 0, 10);
            $marcPorDia[$dia][] = $m;
        }

        // Processar dias
        $dias = [];
        $atual = strtotime($dataInicio);
        $fim   = strtotime($dataFim);
        $totalPresente = 0;
        $totalAusente  = 0;
        $totalFM       = 0;
        $totalMinutos  = 0;

        while ($atual <= $fim) {
            $dataStr   = date('Y-m-d', $atual);
            $diaSemana = (int) date('N', $atual);
            $nomesDias = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

            if ($dataStr < $func['data_admissao']) {
                $atual = strtotime('+1 day', $atual);
                continue;
            }

            $diaInfo = [
                'data'       => $dataStr,
                'dia_nome'   => $nomesDias[$diaSemana],
                'entrada'    => null,
                'saida'      => null,
                'intervalo_inicio' => null,
                'intervalo_fim'    => null,
                'horas'      => 0,
                'estado'     => 'ausente',
                'falta_marcacao' => false
            ];

            if (isset($feriados[$dataStr])) {
                $diaInfo['estado'] = 'feriado';
            } elseif ($diaSemana >= 6) {
                $diaInfo['estado'] = 'fim_semana';
            }

            // Processar marcações
            $mDia = $marcPorDia[$dataStr] ?? [];
            $entrada = null; $saida = null; $intIni = null; $intFim = null;
            $minutosIntervalo = 0;

            foreach ($mDia as $m) {
                $ts = strtotime($m['data_hora']);
                $h  = date('H:i', $ts);
                switch ($m['tipo']) {
                    case 'entrada': $entrada = $ts; $diaInfo['entrada'] = $h; break;
                    case 'saida':   $saida   = $ts; $diaInfo['saida']   = $h; break;
                    case 'inicio_intervalo': $intIni = $ts; $diaInfo['intervalo_inicio'] = $h; break;
                    case 'fim_intervalo':    $intFim = $ts; $diaInfo['intervalo_fim']    = $h;
                        if ($intIni) $minutosIntervalo += (int)round(($ts - $intIni)/60);
                        break;
                }
            }

            if ($entrada) {
                $diaInfo['estado'] = 'presente';
                $totalPresente++;
                $saidaCalc = $saida ?? $entrada;
                $minutos = (int)round(($saidaCalc - $entrada)/60) - $minutosIntervalo;
                $diaInfo['horas'] = round($minutos/60, 2);
                $totalMinutos += $minutos;
            } elseif ($diaInfo['estado'] === 'ausente') {
                // Verificar justificação
                foreach ($justificacoes as $j) {
                    if ($dataStr >= $j['data_inicio'] && $dataStr <= $j['data_fim']) {
                        $diaInfo['estado'] = 'justificado (' . $j['tipo'] . ')';
                        break;
                    }
                }
                if ($diaInfo['estado'] === 'ausente') $totalAusente++;
            }

            // Verificar FM
            foreach ($mfList as $mf) {
                if ($mf['data'] === $dataStr) {
                    $diaInfo['falta_marcacao'] = true;
                    $diaInfo['estado'] = 'fm';
                    $totalFM++;
                    $nota = mb_strtolower($mf['nota_classificacao'] ?? '');
                    if (str_contains($nota, 'entrada')) $diaInfo['entrada'] = 'FM';
                    if (str_contains($nota, 'saída') || str_contains($nota, 'saida')) $diaInfo['saida'] = 'FM';
                    break;
                }
            }

            $dias[] = $diaInfo;
            $atual = strtotime('+1 day', $atual);
        }

        $dados = [
            'empresa' => ['nome' => $tenantInfo['nome_empresa'], 'nif' => $tenantInfo['nif']],
            'funcionario' => [
                'nome' => $func['nome_completo'],
                'numero' => $func['numero_funcionario'],
                'departamento' => $func['departamento'],
                'cargo' => $func['cargo'],
                'data_admissao' => $func['data_admissao']
            ],
            'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim],
            'resumo' => [
                'dias_presente' => $totalPresente,
                'dias_ausente'  => $totalAusente,
                'dias_fm'       => $totalFM,
                'total_horas'   => round($totalMinutos/60, 2)
            ],
            'dias' => $dias,
            'legenda' => 'FM = Falta de Marcação'
        ];

        if ($formato === 'xlsx') {
            return $this->exportarExcel($dados, 'individual', "relatorio_individual_{$func['numero_funcionario']}", $response);
        }

        return $this->json($response, 200, $dados);
    }

    public function assiduidade(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantInfo = $this->getTenantInfo();
        $user   = $request->getAttribute('auth_user');
        $perfil = $request->getAttribute('auth_perfil');

        $params    = $request->getQueryParams();
        $dataInicio = $params['data_inicio'] ?? date('Y-m-01');
        $dataFim    = $params['data_fim']    ?? date('Y-m-t');
        $funcId     = !empty($params['funcionario_id'])   ? (int) $params['funcionario_id']   : null;
        $depId      = !empty($params['departamento_id'])  ? (int) $params['departamento_id']  : null;
        $numFunc    = !empty($params['numero'])            ? $params['numero']                 : null;
        $nomeSearch = !empty($params['search'])            ? $params['search']                 : null;

        if ($dataFim < $dataInicio) {
            return $this->json($response, 422, ['erro' => true, 'mensagem' => 'data_fim não pode ser anterior a data_inicio.']);
        }

        $db = $this->db();

        // 1. Buscar funcionários
        $whereFuncs = ["f.estado = 'activo'"];
        $bindFuncs  = [];
        // Filtro supervisor: apenas a sua equipa
        if ($perfil === 'supervisor' && !empty($user->funcionario_id)) {
            $whereFuncs[] = '(f.supervisor_id = :sid OR f.id = :sid_self)';
            $bindFuncs[':sid'] = (int) $user->funcionario_id;
            $bindFuncs[':sid_self'] = (int) $user->funcionario_id;
        }

        if ($funcId)     { $whereFuncs[] = 'f.id = :fid'; $bindFuncs[':fid'] = $funcId; }
        if ($depId)      { $whereFuncs[] = 'f.departamento_id = :did'; $bindFuncs[':did'] = $depId; }
        if ($numFunc)    { $whereFuncs[] = 'f.numero_funcionario LIKE :num'; $bindFuncs[':num'] = '%'.$numFunc.'%'; }
        if ($nomeSearch) { $whereFuncs[] = 'f.nome_completo LIKE :nome'; $bindFuncs[':nome'] = '%'.$nomeSearch.'%'; }

        $stmtF = $db->prepare("
            SELECT f.id, f.nome_completo, f.numero_funcionario, f.data_admissao,
                   d.nome AS departamento,
                   h.horas_dia AS horas_esperadas_dia,
                   h.tolerancia_entrada_min
            FROM funcionarios f
            LEFT JOIN departamentos d ON f.departamento_id = d.id
            LEFT JOIN funcionario_horario fh ON fh.funcionario_id = f.id AND fh.data_fim IS NULL
            LEFT JOIN horarios h ON fh.horario_id = h.id
            WHERE " . implode(' AND ', $whereFuncs) . "
            ORDER BY f.nome_completo ASC
        ");
        $stmtF->execute($bindFuncs);
        $funcionarios = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        if (empty($funcionarios)) {
            return $this->json($response, 200, ['dados' => [], 'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim]]);
        }

        // 2. Buscar feriados no período
        $feriados = $this->getFeriados($db, $dataInicio, $dataFim);

        // 3. Buscar marcações no período (todos os funcionários de uma vez)
        $ids   = array_column($funcionarios, 'id');
        $inStr = implode(',', $ids);
        $stmtM = $db->query("
            SELECT funcionario_id, tipo, data_hora, origem, editada
            FROM marcacoes
            WHERE funcionario_id IN ({$inStr})
              AND data_hora BETWEEN '{$dataInicio} 00:00:00' AND '{$dataFim} 23:59:59'
            ORDER BY data_hora ASC
        ");
        $todasMarcacoes = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        // 4. Buscar justificações no período
        $stmtJ = $db->query("
            SELECT funcionario_id, data_inicio, data_fim, tipo, estado
            FROM justificacoes
            WHERE funcionario_id IN ({$inStr})
              AND data_inicio <= '{$dataFim}' AND data_fim >= '{$dataInicio}'
              AND estado = 'aprovada'
        ");
        $justificacoes = $stmtJ->fetchAll(PDO::FETCH_ASSOC);

        // 4b. Buscar marcações em falta no período
        $stmtMF = $db->query("
            SELECT funcionario_id, data, nota_classificacao, estado
            FROM marcacoes_em_falta
            WHERE funcionario_id IN ({$inStr})
              AND data BETWEEN '{$dataInicio}' AND '{$dataFim}'
        ");
        $marcacoesFalta = $stmtMF->fetchAll(PDO::FETCH_ASSOC);

        // 5. Calcular por funcionário
        $resultado = [];

        foreach ($funcionarios as $func) {
            $marcFunc = array_filter($todasMarcacoes, fn($m) => $m['funcionario_id'] == $func['id']);
            $justFunc = array_filter($justificacoes, fn($j) => $j['funcionario_id'] == $func['id']);
            $mfFunc   = array_filter($marcacoesFalta, fn($mf) => $mf['funcionario_id'] == $func['id']);

            // Agrupar marcações por dia
            $marcPorDia = [];
            foreach ($marcFunc as $m) {
                $dia = substr($m['data_hora'], 0, 10);
                $marcPorDia[$dia][] = $m;
            }

            // Iterar dias do período
            $dias         = [];
            $totalPresente = 0;
            $totalAusente  = 0;
            $totalJustif   = 0;
            $totalFeriado  = 0;
            $totalFimSem   = 0;

            $atual = strtotime($dataInicio);
            $fim   = strtotime($dataFim);

            while ($atual <= $fim) {
                $dataStr   = date('Y-m-d', $atual);
                $diaSemana = (int) date('N', $atual); // 1=Seg, 7=Dom

                // Só contar a partir da admissão
                if ($dataStr < $func['data_admissao']) {
                    $atual = strtotime('+1 day', $atual);
                    continue;
                }

                $diaInfo = [
                    'data'      => $dataStr,
                    'dia_semana' => $diaSemana,
                    'tipo'      => '',
                    'marcacoes' => $marcPorDia[$dataStr] ?? [],
                    'hora_entrada' => null,
                    'hora_saida'   => null,
                    'tem_falta_marcacao' => false
                ];

                // Extrair entrada/saída das marcações para facilitar o frontend
                if (!empty($diaInfo['marcacoes'])) {
                    foreach ($diaInfo['marcacoes'] as $m) {
                        if ($m['tipo'] === 'entrada' && !$diaInfo['hora_entrada']) {
                            $diaInfo['hora_entrada'] = substr(explode(' ', $m['data_hora'])[1], 0, 5);
                        }
                        if ($m['tipo'] === 'saida') {
                            $diaInfo['hora_saida'] = substr(explode(' ', $m['data_hora'])[1], 0, 5);
                        }
                    }
                }

                // Verificar se há marcação em falta detectada
                foreach ($mfFunc as $mf) {
                    if ($mf['data'] === $dataStr) {
                        $diaInfo['tem_falta_marcacao'] = true;
                        $nota = mb_strtolower($mf['nota_classificacao'] ?? '');
                        if (str_contains($nota, 'entrada')) $diaInfo['hora_entrada'] = 'FM';
                        if (str_contains($nota, 'saída') || str_contains($nota, 'saida')) $diaInfo['hora_saida'] = 'FM';
                        break;
                    }
                }

                if ($diaSemana >= 6) {
                    $diaInfo['tipo'] = 'fim_semana';
                    $totalFimSem++;
                } elseif (isset($feriados[$dataStr])) {
                    $diaInfo['tipo']    = 'feriado';
                    $diaInfo['feriado'] = $feriados[$dataStr];
                    $totalFeriado++;
                } elseif (!empty($marcPorDia[$dataStr])) {
                    $diaInfo['tipo'] = 'presente';
                    $totalPresente++;
                } else {
                    // Verificar justificação
                    $justificado = false;
                    foreach ($justFunc as $j) {
                        if ($dataStr >= $j['data_inicio'] && $dataStr <= $j['data_fim']) {
                            $justificado   = true;
                            $diaInfo['justificacao'] = $j['tipo'];
                            break;
                        }
                    }
                    $diaInfo['tipo'] = $justificado ? 'justificado' : 'ausente';
                    if ($justificado) $totalJustif++; else $totalAusente++;
                }

                $dias[] = $diaInfo;
                $atual  = strtotime('+1 day', $atual);
            }

            $diasUteis = $totalPresente + $totalAusente + $totalJustif;

            $resultado[] = [
                'funcionario'         => [
                    'id'                  => $func['id'],
                    'nome'                => $func['nome_completo'],
                    'numero'              => $func['numero_funcionario'],
                    'departamento'        => $func['departamento'],
                ],
                'resumo' => [
                    'dias_uteis'          => $diasUteis,
                    'dias_presente'       => $totalPresente,
                    'dias_ausente'        => $totalAusente,
                    'dias_justificados'   => $totalJustif,
                    'dias_feriado'        => $totalFeriado,
                    'taxa_presenca'       => $diasUteis > 0 ? round($totalPresente / $diasUteis * 100, 1) : 0,
                ],
                'dias' => $dias,
            ];
        }

        return $this->json($response, 200, [
            'empresa' => ['nome' => $tenantInfo['nome_empresa'], 'nif' => $tenantInfo['nif']],
            'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim],
            'total_funcionarios' => count($resultado),
            'dados'  => $resultado,
            'gerado_em' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * GET /api/relatorios/horas
     * Params: data_inicio, data_fim, funcionario_id (opcional)
     *
     * Calcula por funcionário:
     * - horas efectivas trabalhadas (entrada - saída - intervalos)
     * - horas esperadas (horário × dias presentes)
     * - horas extra (LGT Art.º 96.º)
     * - minutos de atraso acumulados
     * - minutos de saída antecipada
     */
    public function horas(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantInfo = $this->getTenantInfo();
        $user   = $request->getAttribute('auth_user');
        $perfil = $request->getAttribute('auth_perfil');

        $params     = $request->getQueryParams();
        $dataInicio = $params['data_inicio'] ?? date('Y-m-01');
        $dataFim    = $params['data_fim']    ?? date('Y-m-t');
        $funcId     = !empty($params['funcionario_id']) ? (int) $params['funcionario_id'] : null;

        $db = $this->db();

        $whereFuncs = ["f.estado = 'activo'"];
        $bindFuncs  = [];
        $numFuncH    = !empty($params['numero'])  ? $params['numero']  : null;
        $nomeSearchH = !empty($params['search'])  ? $params['search']  : null;
        // Filtro supervisor: apenas a sua equipa
        if ($perfil === 'supervisor' && !empty($user->funcionario_id)) {
            $whereFuncs[] = '(f.supervisor_id = :sid OR f.id = :sid_self)';
            $bindFuncs[':sid'] = (int) $user->funcionario_id;
            $bindFuncs[':sid_self'] = (int) $user->funcionario_id;
        }

        if ($funcId)      { $whereFuncs[] = 'f.id = :fid'; $bindFuncs[':fid'] = $funcId; }
        if (!empty($params['departamento_id'])) { $whereFuncs[] = 'f.departamento_id = :did'; $bindFuncs[':did'] = (int)$params['departamento_id']; }
        if ($numFuncH)    { $whereFuncs[] = 'f.numero_funcionario LIKE :num'; $bindFuncs[':num'] = '%'.$numFuncH.'%'; }
        if ($nomeSearchH) { $whereFuncs[] = 'f.nome_completo LIKE :nome'; $bindFuncs[':nome'] = '%'.$nomeSearchH.'%'; }

        $stmtF = $db->prepare("
            SELECT f.id, f.nome_completo, f.numero_funcionario,
                   d.nome AS departamento,
                   h.horas_dia AS horas_esperadas_dia,
                   h.horas_semana AS horas_esperadas_semana,
                   h.tolerancia_entrada_min,
                   ht.hora_entrada AS hora_entrada_padrao,
                   ht.hora_saida   AS hora_saida_padrao
            FROM funcionarios f
            LEFT JOIN departamentos d ON f.departamento_id = d.id
            LEFT JOIN funcionario_horario fh ON fh.funcionario_id = f.id AND fh.data_fim IS NULL
            LEFT JOIN horarios h ON fh.horario_id = h.id
            LEFT JOIN horario_turnos ht ON ht.horario_id = h.id AND ht.dia_semana = 1
            WHERE " . implode(' AND ', $whereFuncs) . "
            ORDER BY f.nome_completo ASC
        ");
        $stmtF->execute($bindFuncs);
        $funcionarios = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        if (empty($funcionarios)) {
            return $this->json($response, 200, ['dados' => [], 'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim]]);
        }

        $feriados = $this->getFeriados($db, $dataInicio, $dataFim);
        $ids      = array_column($funcionarios, 'id');
        $inStr    = implode(',', $ids);

        $fimQuery = $dataFim . ' 23:59:59';
        foreach ($funcionarios as $funcCheck) {
            if ($this->periodoTemTurnoNocturno($db, (int)$funcCheck['id'], $dataInicio, $dataFim)) {
                $fimQuery = date('Y-m-d', strtotime($dataFim . ' +1 day')) . ' 12:00:00';
                break;
            }
        }
        $stmtM = $db->prepare("
            SELECT funcionario_id, tipo, data_hora
            FROM marcacoes
            WHERE funcionario_id IN ({$inStr})
              AND data_hora BETWEEN :ini AND :fim
            ORDER BY funcionario_id, data_hora ASC
        ");
        $stmtM->execute([':ini' => $dataInicio . ' 00:00:00', ':fim' => $fimQuery]);
        $todasMarcacoes = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        // Buscar marcações em falta no período
        $stmtMF = $db->query("
            SELECT funcionario_id, data, nota_classificacao, estado
            FROM marcacoes_em_falta
            WHERE funcionario_id IN ({$inStr})
              AND data BETWEEN '{$dataInicio}' AND '{$dataFim}'
        ");
        $marcacoesFalta = $stmtMF->fetchAll(PDO::FETCH_ASSOC);

        $stmtCfg = $db->query("SELECT valor FROM configuracoes WHERE chave = 'horas_extra_entrada_antecipada'");
        $rowCfg = $stmtCfg->fetch(PDO::FETCH_ASSOC);
        $contarEntradaAntecipada = ($rowCfg && $rowCfg['valor'] === '1');

        $resultado = [];
        $escalaService = new \App\Services\EscalaService($db);

        foreach ($funcionarios as $func) {
            $marcFunc   = array_values(array_filter($todasMarcacoes, fn($m) => $m['funcionario_id'] == $func['id']));
            $mfFunc     = array_filter($marcacoesFalta, fn($mf) => $mf['funcionario_id'] == $func['id']);
            $marcPorDia = [];
            foreach ($marcFunc as $m) {
                $dia = substr($m['data_hora'], 0, 10);
                $hora = (int) substr($m['data_hora'], 11, 2);
                if ($m['tipo'] === 'saida' && $hora < 12) {
                    $diaAnterior = date('Y-m-d', strtotime($dia . ' -1 day'));
                    $turnoAnterior = $escalaService->calcularTurnoEm((int)$func['id'], $diaAnterior);
                    if ($turnoAnterior && $turnoAnterior['atravessa_dia_civil']) {
                        $dia = $diaAnterior;
                    }
                }
                $marcPorDia[$dia][] = $m;
            }

            $horasEsperadasDia = (float) ($func['horas_esperadas_dia'] ?? 8);
            $tolerancia        = (int)   ($func['tolerancia_entrada_min'] ?? 10);
            $horaPadraoEntrada = $func['hora_entrada_padrao'] ?? '08:00:00';
            $horaPadraoSaida   = $func['hora_saida_padrao']   ?? '17:00:00';

            $totalMinutosEfetivos = 0;
            $totalMinutosEsperados = 0;
            $totalMinutosAtraso    = 0;
            $totalMinutosSaidaAnt  = 0;
            $totalDiasPresente     = 0;
            $detalhesDia           = [];

            foreach ($marcPorDia as $dia => $marcacoes) {
                $diaSemana = (int) date('N', strtotime($dia));

                $tipoDia = 'util';
                if (isset($feriados[$dia])) {
                    $tipoDia = 'feriado';
                } elseif ($diaSemana === 6) {
                    $tipoDia = 'sabado';
                } elseif ($diaSemana === 7) {
                    $tipoDia = 'domingo';
                }

                $turnoHoras = $escalaService->calcularTurnoEm((int)$func['id'], $dia);
                $horasEsperadasDiaTurno = $horasEsperadasDia; // fallback: valor do horário
                if ($turnoHoras && $turnoHoras['tipo'] !== 'folga' && $turnoHoras['horas_efectivas'] !== null) {
                    $horasEsperadasDiaTurno = (float) $turnoHoras['horas_efectivas'];
                }

                $entrada         = null;
                $saida           = null;
                $minutosIntervalo = 0;
                $inicioIntervalo  = null;

                foreach ($marcacoes as $m) {
                    $ts = strtotime($m['data_hora']);
                    switch ($m['tipo']) {
                        case 'entrada':
                            $entrada = $ts;
                            break;
                        case 'saida':
                            $saida = $ts;
                            break;
                        case 'inicio_intervalo':
                            $inicioIntervalo = $ts;
                            break;
                        case 'fim_intervalo':
                            if ($inicioIntervalo) {
                                $minutosIntervalo += (int) round(($ts - $inicioIntervalo) / 60);
                                $inicioIntervalo   = null;
                            }
                            break;
                    }
                }

                if (!$entrada) continue;

                $saidaEfetiva = $saida ?? ($entrada + ($horasEsperadasDiaTurno * 3600));
                $minutosTrabalhados = (int) round(($saidaEfetiva - $entrada) / 60) - $minutosIntervalo;

                // Ajuste se a entrada antecipada não deve contar (configuração horas_extra_entrada_antecipada)
                if (!$contarEntradaAntecipada && $turnoHoras && $turnoHoras['hora_entrada']) {
                    $entradaPrevista = strtotime($dia . ' ' . substr($turnoHoras['hora_entrada'], 0, 5));
                    if ($entrada < $entradaPrevista) {
                        $minutosAntecipados = (int) round(($entradaPrevista - $entrada) / 60);
                        $minutosTrabalhados -= $minutosAntecipados;
                    }
                }

                $minutosEsperados   = (int) ($horasEsperadasDiaTurno * 60);

                if ($turnoHoras && $turnoHoras['tipo'] === 'folga') {
                    $minutosEsperados = 0;
                }

                // Calcular atraso (tolerância incluída)
                $entradaRef = ($turnoHoras && $turnoHoras['hora_entrada']) ? substr($turnoHoras['hora_entrada'], 0, 5) : substr($horaPadraoEntrada, 0, 5);
                $saidaRef   = ($turnoHoras && $turnoHoras['hora_saida'])   ? substr($turnoHoras['hora_saida'], 0, 5)   : substr($horaPadraoSaida, 0, 5);

                $entradaPrevista = strtotime($dia . ' ' . $entradaRef);
                $atrasoMin = max(0, (int) round(($entrada - $entradaPrevista) / 60) - $tolerancia);

                // Calcular saída antecipada
                $saidaPrevista = strtotime($dia . ' ' . $saidaRef);
                if ($turnoHoras && $turnoHoras['atravessa_dia_civil']) {
                    $saidaPrevista = strtotime($dia . ' ' . $saidaRef . ' +1 day');
                }
                $saidaAntMin = $saida ? max(0, (int) round(($saidaPrevista - $saida) / 60)) : 0;

                $totalMinutosEfetivos  += $minutosTrabalhados;
                $totalMinutosEsperados += $minutosEsperados;
                $totalMinutosAtraso    += $atrasoMin;
                $totalMinutosSaidaAnt  += $saidaAntMin;
                $totalDiasPresente++;

                $hEntrada = $entrada ? date('H:i', $entrada) : null;
                $hSaida   = $saida   ? date('H:i', $saida)   : null;
                $temFM    = false;

                foreach ($mfFunc as $mf) {
                    if ($mf['data'] === $dia) {
                        $temFM = true;
                        $nota = mb_strtolower($mf['nota_classificacao'] ?? '');
                        if (str_contains($nota, 'entrada')) $hEntrada = 'FM';
                        if (str_contains($nota, 'saída') || str_contains($nota, 'saida')) $hSaida = 'FM';
                        break;
                    }
                }

                $detalhesDia[] = [
                    'data'                => $dia,
                    'entrada'             => $hEntrada,
                    'saida'               => $hSaida,
                    'tem_falta_marcacao'  => $temFM,
                    'minutos_intervalo'   => $minutosIntervalo,
                    'minutos_trabalhados' => $minutosTrabalhados,
                    'minutos_esperados'   => $minutosEsperados,
                    'minutos_extra'       => ($turnoHoras && $turnoHoras['tipo'] === 'folga') ? 0 : max(0, $minutosTrabalhados - $minutosEsperados),
                    'minutos_deficit'     => ($turnoHoras && $turnoHoras['tipo'] === 'folga') ? 0 : max(0, $minutosEsperados - $minutosTrabalhados),
                    'atraso_min'          => $atrasoMin,
                    'saida_antecipada_min' => $saidaAntMin,
                    'tipo_dia'            => $tipoDia,
                ];
            }

            $minutosExtra  = max(0, $totalMinutosEfetivos - $totalMinutosEsperados);
            $minutosDeficit = max(0, $totalMinutosEsperados - $totalMinutosEfetivos);

            $resultado[] = [
                'funcionario' => [
                    'id'          => $func['id'],
                    'nome'        => $func['nome_completo'],
                    'numero'      => $func['numero_funcionario'],
                    'departamento' => $func['departamento'],
                ],
                'resumo' => [
                    'dias_presentes'          => $totalDiasPresente,
                    'horas_efectivas'         => round($totalMinutosEfetivos / 60, 2),
                    'horas_esperadas'         => round($totalMinutosEsperados / 60, 2),
                    'horas_extra'             => round($minutosExtra / 60, 2),
                    'horas_deficit'           => round($minutosDeficit / 60, 2),
                    'minutos_atraso_total'    => $totalMinutosAtraso,
                    'minutos_saida_ant_total' => $totalMinutosSaidaAnt,
                    'saldo_horas'             => round(($totalMinutosEfetivos - $totalMinutosEsperados) / 60, 2),
                ],
                'dias' => $detalhesDia,
            ];
        }

        return $this->json($response, 200, [
            'empresa' => ['nome' => $tenantInfo['nome_empresa'], 'nif' => $tenantInfo['nif']],
            'periodo'            => ['inicio' => $dataInicio, 'fim' => $dataFim],
            'total_funcionarios' => count($resultado),
            'dados'              => $resultado,
            'gerado_em'          => date('Y-m-d H:i:s'),
            'nota_legal'         => 'Horas extra calculadas conforme LGT Lei 7/15, Art.º 96.º (limite 8h/dia, 44h/semana).',
        ]);
    }

    /**
     * GET /api/relatorios/departamento/{departamento_id}
     */
    public function porDepartamento(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenantInfo = $this->getTenantInfo();
        $depId = (int) $args['departamento_id'];
        $params = $request->getQueryParams();
        $inicio = $params['inicio'] ?? date('Y-m-01');
        $fim    = $params['fim']    ?? date('Y-m-t');
        $formato = $params['formato'] ?? 'json';

        $user = $request->getAttribute('auth_user');
        $perfil = $request->getAttribute('auth_perfil');

        $db = $this->db();
        $stmt = $db->prepare("SELECT nome FROM departamentos WHERE id = :id");
        $stmt->execute([':id' => $depId]);
        $depNome = $stmt->fetchColumn();

        if (!$depNome) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Departamento não encontrado.']);
        }

        // RBAC: Supervisor só pode aceder ao departamento dos funcionários da sua equipa.
        if ($perfil === 'supervisor') {
            $stmtCheck = $db->prepare("SELECT 1 FROM funcionarios WHERE departamento_id = :did AND (supervisor_id = :sid OR id = :sid_self) LIMIT 1");
            $stmtCheck->execute([':did' => $depId, ':sid' => $user->funcionario_id, ':sid_self' => $user->funcionario_id]);
            if (!$stmtCheck->fetch()) {
                return $this->json($response, 403, ['erro' => true, 'mensagem' => 'Sem permissão para aceder ao relatório deste departamento.']);
            }
        }

        // Reutilizar a lógica de assiduidade
        $queryParams = [
            'data_inicio' => $inicio,
            'data_fim' => $fim,
            'departamento_id' => $depId
        ];
        $newRequest = $request->withQueryParams($queryParams);
        $resAssiduidade = $this->assiduidade($newRequest, new Response());
        $dataAssiduidade = json_decode((string)$resAssiduidade->getBody(), true);

        if ($resAssiduidade->getStatusCode() !== 200) {
            return $resAssiduidade;
        }

        // Agregados
        $totalPresencas = 0;
        $totalFaltas = 0;
        $totalFM = 0;

        foreach ($dataAssiduidade['dados'] as $func) {
            $totalPresencas += $func['resumo']['dias_presente'];
            $totalFaltas += $func['resumo']['dias_ausente'];
            foreach ($func['dias'] as $dia) {
                if ($dia['tem_falta_marcacao']) $totalFM++;
            }
        }

        $dados = [
            'empresa' => ['nome' => $tenantInfo['nome_empresa'], 'nif' => $tenantInfo['nif']],
            'cabecalho' => [
                'departamento' => $depNome,
                'periodo' => ['inicio' => $inicio, 'fim' => $fim],
                'totais' => [
                    'total_funcionarios' => count($dataAssiduidade['dados']),
                    'total_presencas' => $totalPresencas,
                    'total_faltas' => $totalFaltas,
                    'total_fm' => $totalFM
                ]
            ],
            'dados' => $dataAssiduidade['dados'],
            'periodo' => $dataAssiduidade['periodo']
        ];

        if ($formato === 'xlsx') {
            return $this->exportarExcel($dados, 'departamento', "relatorio_departamento_{$depId}", $response);
        }

        return $this->json($response, 200, $dados);
    }

    /**
     * GET /api/relatorios/escala/{escala_id}
     */
    public function porEscala(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $tenantInfo = $this->getTenantInfo();
        $escalaId = (int) $args['escala_id'];
        $params = $request->getQueryParams();
        $inicio = $params['inicio'] ?? date('Y-m-01');
        $fim    = $params['fim']    ?? date('Y-m-t');
        $formato = $params['formato'] ?? 'json';

        $db = $this->db();
        $user = $request->getAttribute('auth_user');
        $perfil = $request->getAttribute('auth_perfil');

        $stmtE = $db->prepare("SELECT nome FROM escalas WHERE id = :id AND activo = 1");
        $stmtE->execute([':id' => $escalaId]);
        $escalaNome = $stmtE->fetchColumn();

        if (!$escalaNome) {
            return $this->json($response, 404, ['erro' => true, 'mensagem' => 'Escala não encontrada.']);
        }

        $sqlFuncs = "
            SELECT f.id, f.nome_completo, f.numero_funcionario, f.data_admissao,
                   d.nome AS departamento
            FROM funcionario_escala fe
            JOIN funcionarios f ON fe.funcionario_id = f.id
            LEFT JOIN departamentos d ON f.departamento_id = d.id
            WHERE fe.escala_id = :eid
              AND fe.data_inicio <= :fim
              AND (fe.data_fim IS NULL OR fe.data_fim >= :ini)
        ";
        $bindFuncs = [':eid' => $escalaId, ':ini' => $inicio, ':fim' => $fim];

        if ($perfil === 'supervisor') {
            $sqlFuncs .= " AND (f.supervisor_id = :sid OR f.id = :sid_self)";
            $bindFuncs[':sid'] = (int) $user->funcionario_id;
            $bindFuncs[':sid_self'] = (int) $user->funcionario_id;
        }

        $stmtF = $db->prepare($sqlFuncs . " ORDER BY f.nome_completo ASC");
        $stmtF->execute($bindFuncs);
        $funcionarios = $stmtF->fetchAll(PDO::FETCH_ASSOC);

        if (empty($funcionarios)) {
            return $this->json($response, 200, [
                'cabecalho' => ['escala' => $escalaNome, 'periodo' => ['inicio' => $inicio, 'fim' => $fim]],
                'dados' => []
            ]);
        }

        $escalaService = new \App\Services\EscalaService($db);
        $ids = array_column($funcionarios, 'id');
        $inStr = implode(',', $ids);

        $stmtM = $db->prepare("
            SELECT funcionario_id, tipo, data_hora
            FROM marcacoes
            WHERE funcionario_id IN ($inStr)
              AND data_hora BETWEEN :ini AND :fim
            ORDER BY data_hora ASC
        ");
        $stmtM->execute([':ini' => $inicio . ' 00:00:00', ':fim' => $fim . ' 23:59:59']);
        $todasMarcacoes = $stmtM->fetchAll(PDO::FETCH_ASSOC);

        $stmtMF = $db->prepare("
            SELECT funcionario_id, data, nota_classificacao
            FROM marcacoes_em_falta
            WHERE funcionario_id IN ($inStr)
              AND data BETWEEN :ini AND :fim
        ");
        $stmtMF->execute([':ini' => $inicio, ':fim' => $fim]);
        $todasMF = $stmtMF->fetchAll(PDO::FETCH_ASSOC);

        $resultado = [];
        foreach ($funcionarios as $func) {
            $dias = [];
            $totalPresente = 0;
            $totalAusente = 0;
            $totalFM = 0;

            $atual = strtotime($inicio);
            $fimTs = strtotime($fim);

            while ($atual <= $fimTs) {
                $dataStr = date('Y-m-d', $atual);
                if ($dataStr < $func['data_admissao']) {
                    $atual = strtotime('+1 day', $atual);
                    continue;
                }

                $turnoEsperado = $escalaService->calcularTurnoEm($func['id'], $dataStr);
                $marcacoesDia = array_filter($todasMarcacoes, fn($m) => $m['funcionario_id'] == $func['id'] && str_starts_with($m['data_hora'], $dataStr));
                $mfDia = array_filter($todasMF, fn($mf) => $mf['funcionario_id'] == $func['id'] && $mf['data'] == $dataStr);

                $diaInfo = [
                    'data' => $dataStr,
                    'turno_esperado' => $turnoEsperado ? $turnoEsperado['turno_nome'] : 'Sem escala',
                    'tipo_esperado' => $turnoEsperado ? $turnoEsperado['tipo'] : 'folga',
                    'presenca' => !empty($marcacoesDia) ? 'presente' : 'ausente',
                    'falta_marcacao' => !empty($mfDia)
                ];

                if ($diaInfo['presenca'] === 'presente') {
                    $totalPresente++;
                } elseif ($diaInfo['tipo_esperado'] === 'trabalho') {
                    $totalAusente++;
                }
                if ($diaInfo['falta_marcacao']) {
                    $totalFM++;
                }

                $dias[] = $diaInfo;
                $atual = strtotime('+1 day', $atual);
            }

            $resultado[] = [
                'funcionario' => $func,
                'resumo' => [
                    'presencas' => $totalPresente,
                    'faltas' => $totalAusente,
                    'fm' => $totalFM
                ],
                'dias' => $dias
            ];
        }

        $dados = [
            'empresa' => ['nome' => $tenantInfo['nome_empresa'], 'nif' => $tenantInfo['nif']],
            'cabecalho' => [
                'escala' => $escalaNome,
                'periodo' => ['inicio' => $inicio, 'fim' => $fim]
            ],
            'dados' => $resultado
        ];

        if ($formato === 'xlsx') {
            return $this->exportarExcel($dados, 'escala', "relatorio_escala_{$escalaId}", $response);
        }

        return $this->json($response, 200, $dados);
    }

    /**
     * Devolve feriados do período como array indexado por data
     */
    private function getFeriados(PDO $db, string $inicio, string $fim): array
    {
        $stmt = $db->prepare("
            SELECT data, nome, meio_dia
            FROM feriados
            WHERE data BETWEEN :ini AND :fim
               OR (recorrente = 1 AND DATE_FORMAT(data, '%m-%d') BETWEEN DATE_FORMAT(:ini2, '%m-%d') AND DATE_FORMAT(:fim2, '%m-%d'))
        ");
        $stmt->execute([':ini' => $inicio, ':fim' => $fim, ':ini2' => $inicio, ':fim2' => $fim]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $result[$f['data']] = ['nome' => $f['nome'], 'meio_dia' => (bool) $f['meio_dia']];
        }
        return $result;
    }

    /**
     * GET /api/relatorios/assiduidade/exportar?formato=csv&...
     * GET /api/relatorios/horas/exportar?formato=csv&...
     */
    public function exportarAssiduidade(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->exportar($request, $response, 'assiduidade');
    }

    public function exportarHoras(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->exportar($request, $response, 'horas');
    }

    private function exportar(ServerRequestInterface $request, ResponseInterface $response, string $tipo): ResponseInterface
    {
        $formato = $request->getQueryParams()['formato'] ?? 'csv';

        // Obter dados usando o método existente
        $dadosResponse = $tipo === 'assiduidade'
            ? $this->assiduidade($request, new Response())
            : $this->horas($request, new Response());

        $body = json_decode((string) $dadosResponse->getBody(), true);
        $dados = $body['dados'] ?? [];
        $periodo = $body['periodo'] ?? [];

        $filename = "relatorio_{$tipo}_{$periodo['inicio']}_{$periodo['fim']}";

        if ($formato === 'xlsx') {
            return $this->exportarExcel($body, $tipo, $filename, $response);
        }

        if ($formato === 'csv') {
            return $this->exportarCSV($dados, $tipo, $filename, $response);
        }

        return $dadosResponse;
    }

    private function exportarExcel(array $dados, string $tipo, string $filename, ResponseInterface $response): ResponseInterface
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'CCCCCC']
            ]
        ];

        $fmStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FFFF00']
            ]
        ];

        if ($tipo === 'assiduidade' || $tipo === 'departamento') {
            $startRow = 1;
            if ($tipo === 'departamento') {
                $sheet->setCellValue('A1', 'Relatório de Assiduidade por Departamento');
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
                $sheet->setCellValue('A2', 'Departamento: ' . $dados['cabecalho']['departamento']);
                $sheet->setCellValue('A3', 'Período: ' . $dados['cabecalho']['periodo']['inicio'] . ' a ' . $dados['cabecalho']['periodo']['fim']);
                $sheet->setCellValue('A4', 'Total Funcionários: ' . $dados['cabecalho']['totais']['total_funcionarios']);
                $sheet->setCellValue('C4', 'Total Presenças: ' . $dados['cabecalho']['totais']['total_presencas']);
                $sheet->setCellValue('E4', 'Total Faltas: ' . $dados['cabecalho']['totais']['total_faltas']);
                $sheet->setCellValue('G4', 'Total FM: ' . $dados['cabecalho']['totais']['total_fm']);
                $startRow = 6;
            }

            $sheet->fromArray(['Nº', 'Nome', 'Departamento', 'Dias Úteis', 'Presentes', 'Ausentes', 'Justificados', 'Feriados', 'Taxa Presença (%)'], NULL, 'A' . $startRow);
            $sheet->getStyle('A' . $startRow . ':I' . $startRow)->applyFromArray($headerStyle);

            $row = $startRow + 1;
            foreach ($dados['dados'] as $r) {
                $s = $r['resumo'];
                $sheet->fromArray([
                    $r['funcionario']['numero'],
                    $r['funcionario']['nome'],
                    $r['funcionario']['departamento'] ?? '',
                    $s['dias_uteis'],
                    $s['dias_presente'],
                    $s['dias_ausente'],
                    $s['dias_justificados'],
                    $s['dias_feriado'],
                    $s['taxa_presenca'],
                ], NULL, 'A' . $row);
                $row++;
            }
        } elseif ($tipo === 'horas') {
            $sheet->fromArray(['Nº', 'Nome', 'Departamento', 'H. Efectivas', 'H. Esperadas', 'H. Extra', 'H. Défice', 'Atrasos (min)', 'Saldo (h)'], NULL, 'A1');
            $sheet->getStyle('A1:I1')->applyFromArray($headerStyle);

            $row = 2;
            foreach ($dados['dados'] as $r) {
                $s = $r['resumo'];
                $sheet->fromArray([
                    $r['funcionario']['numero'],
                    $r['funcionario']['nome'],
                    $r['funcionario']['departamento'] ?? '',
                    $s['horas_efectivas'],
                    $s['horas_esperadas'],
                    $s['horas_extra'],
                    $s['horas_deficit'],
                    $s['minutos_atraso_total'],
                    $s['saldo_horas'],
                ], NULL, 'A' . $row);
                $row++;
            }
        } elseif ($tipo === 'individual') {
            $f = $dados['funcionario'];
            $sheet->setCellValue('A1', 'Relatório Individual de Assiduidade');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

            $sheet->setCellValue('A3', 'Funcionário:'); $sheet->setCellValue('B3', $f['nome']);
            $sheet->setCellValue('A4', 'Número:');      $sheet->setCellValue('B4', $f['numero']);
            $sheet->setCellValue('A5', 'Departamento:'); $sheet->setCellValue('B5', $f['departamento']);
            $sheet->setCellValue('A6', 'Cargo:');        $sheet->setCellValue('B6', $f['cargo']);
            $sheet->setCellValue('A7', 'Período:');      $sheet->setCellValue('B7', $dados['periodo']['inicio'] . ' a ' . $dados['periodo']['fim']);
            $sheet->getStyle('A3:A7')->getFont()->setBold(true);

            $sheet->fromArray(['Data', 'Dia', 'Entrada', 'Saída', 'Iníc. Int.', 'Fim Int.', 'Horas', 'Estado'], NULL, 'A9');
            $sheet->getStyle('A9:H9')->applyFromArray($headerStyle);

            $row = 10;
            foreach ($dados['dias'] as $d) {
                $sheet->setCellValue('A' . $row, $d['data']);
                $sheet->setCellValue('B' . $row, $d['dia_nome']);
                $sheet->setCellValue('C' . $row, $d['entrada']);
                $sheet->setCellValue('D' . $row, $d['saida']);
                $sheet->setCellValue('E' . $row, $d['intervalo_inicio']);
                $sheet->setCellValue('F' . $row, $d['intervalo_fim']);
                $sheet->setCellValue('G' . $row, $d['horas']);
                $sheet->setCellValue('H' . $row, $d['estado']);

                if ($d['entrada'] === 'FM') $sheet->getStyle('C' . $row)->applyFromArray($fmStyle);
                if ($d['saida'] === 'FM')   $sheet->getStyle('D' . $row)->applyFromArray($fmStyle);

                $row++;
            }

            $row++;
            $sheet->setCellValue('A' . $row, 'Resumo:');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
            $sheet->setCellValue('A' . $row, 'Dias Presente:');   $sheet->setCellValue('B' . $row, $dados['resumo']['dias_presente']);
            $sheet->setCellValue('A' . ($row+1), 'Dias Ausente:'); $sheet->setCellValue('B' . ($row+1), $dados['resumo']['dias_ausente']);
            $sheet->setCellValue('A' . ($row+2), 'Dias FM:');      $sheet->setCellValue('B' . ($row+2), $dados['resumo']['dias_fm']);
            $sheet->setCellValue('A' . ($row+3), 'Total Horas:');  $sheet->setCellValue('B' . ($row+3), $dados['resumo']['total_horas']);
            $sheet->getStyle('A'.$row.':A'.($row+3))->getFont()->setBold(true);

            $row += 5;
            $sheet->setCellValue('A' . $row, $dados['legenda']);
            $sheet->getStyle('A' . $row)->getFont()->setItalic(true);
        } elseif ($tipo === 'escala') {
            $sheet->setCellValue('A1', 'Relatório de Assiduidade por Escala');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $sheet->setCellValue('A2', 'Escala: ' . $dados['cabecalho']['escala']);
            $sheet->setCellValue('A3', 'Período: ' . $dados['cabecalho']['periodo']['inicio'] . ' a ' . $dados['cabecalho']['periodo']['fim']);

            $sheet->fromArray(['Nº', 'Nome', 'Departamento', 'Presenças', 'Faltas', 'FM'], NULL, 'A5');
            $sheet->getStyle('A5:F5')->applyFromArray($headerStyle);

            $row = 6;
            foreach ($dados['dados'] as $r) {
                $sheet->fromArray([
                    $r['funcionario']['numero_funcionario'],
                    $r['funcionario']['nome_completo'],
                    $r['funcionario']['departamento'] ?? '',
                    $r['resumo']['presencas'],
                    $r['resumo']['faltas'],
                    $r['resumo']['fm']
                ], NULL, 'A' . $row);
                $row++;
            }
        } elseif ($tipo === 'marcacoes_diarias') {
            $f = $dados['funcionario'];
            $sheet->setCellValue('A1', 'Relatório de Marcações Diárias');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

            $sheet->setCellValue('A3', 'Funcionário:'); $sheet->setCellValue('B3', $f['nome']);
            $sheet->setCellValue('A4', 'Número:');      $sheet->setCellValue('B4', $f['numero']);
            $sheet->setCellValue('A5', 'Período:');      $sheet->setCellValue('B5', $dados['periodo']['inicio'] . ' a ' . $dados['periodo']['fim']);
            $sheet->getStyle('A3:A5')->getFont()->setBold(true);

            $sheet->fromArray(['Data', 'Dia', 'Hora', 'Tipo', 'Origem'], NULL, 'A7');
            $sheet->getStyle('A7:E7')->applyFromArray($headerStyle);

            $row = 8;
            foreach ($dados['dias'] as $d) {
                if (empty($d['marcacoes'])) {
                    $sheet->fromArray([$d['data'], $d['dia_semana'], 'Sem marcações'], NULL, 'A' . $row);
                    $row++;
                    continue;
                }
                foreach ($d['marcacoes'] as $idx => $m) {
                    $sheet->fromArray([
                        $idx === 0 ? $d['data'] : '',
                        $idx === 0 ? $d['dia_semana'] : '',
                        $m['hora'],
                        $m['tipo'],
                        $m['origem']
                    ], NULL, 'A' . $row);
                    $row++;
                }
                $sheet->setCellValue('C' . $row, 'Resumo:');
                $priEnt = $d['resumo']['primeira_entrada'] ?? '--:--';
                $ultSai = $d['resumo']['ultima_saida'] ?? '--:--';
                $totH = $d['resumo']['total_horas'];
                $sheet->setCellValue('D' . $row, "Ent: {$priEnt} | Sai: {$ultSai} | Total: {$totH}h");
                $sheet->getStyle("C$row:D$row")->getFont()->setItalic(true)->setSize(9);
                $row++;
            }
        }

        $lastCol = match($tipo) {
            'individual' => 'H',
            'escala' => 'F',
            'marcacoes_diarias' => 'E',
            default => 'I'
        };
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'excel');
        $writer->save($tempFile);

        $stream = fopen($tempFile, 'r+');
        $resp = new Response(200);
        $resp->getBody()->write(fread($stream, filesize($tempFile)));
        fclose($stream);
        unlink($tempFile);

        return $resp
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}.xlsx\"")
            ->withHeader('Cache-Control', 'no-cache');
    }

    private function exportarCSV(array $dados, string $tipo, string $filename, ResponseInterface $response): ResponseInterface
    {
        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF"); // BOM UTF-8

        if ($tipo === 'assiduidade') {
            fputcsv($output, ['Nº', 'Nome', 'Departamento', 'Dias Úteis', 'Presentes', 'Ausentes', 'Justificados', 'Feriados', 'Taxa Presença (%)'], ';');
            foreach ($dados as $r) {
                $s = $r['resumo'];
                fputcsv($output, [
                    $r['funcionario']['numero'],
                    $r['funcionario']['nome'],
                    $r['funcionario']['departamento'] ?? '',
                    $s['dias_uteis'],
                    $s['dias_presente'],
                    $s['dias_ausente'],
                    $s['dias_justificados'],
                    $s['dias_feriado'],
                    $s['taxa_presenca'],
                ], ';');
            }
        } else {
            fputcsv($output, ['Nº', 'Nome', 'Departamento', 'H. Efectivas', 'H. Esperadas', 'H. Extra', 'H. Défice', 'Atrasos (min)', 'Saldo (h)'], ';');
            foreach ($dados as $r) {
                $s = $r['resumo'];
                fputcsv($output, [
                    $r['funcionario']['numero'],
                    $r['funcionario']['nome'],
                    $r['funcionario']['departamento'] ?? '',
                    $s['horas_efectivas'],
                    $s['horas_esperadas'],
                    $s['horas_extra'],
                    $s['horas_deficit'],
                    $s['minutos_atraso_total'],
                    $s['saldo_horas'],
                ], ';');
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $resp = new Response(200);
        $resp->getBody()->write($csv);
        return $resp
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}.csv\"")
            ->withHeader('Cache-Control', 'no-cache');
    }

    private function periodoTemTurnoNocturno(PDO $db, int $funcId, string $dataInicio, string $dataFim): bool
    {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM funcionario_escala fe
            JOIN escala_turnos et ON fe.escala_id = et.escala_id
            JOIN turnos t ON et.turno_id = t.id
            WHERE fe.funcionario_id = :fid
              AND fe.data_inicio <= :fim
              AND (fe.data_fim IS NULL OR fe.data_fim >= :ini)
              AND t.atravessa_dia_civil = 1
        ");
        $stmt->execute([
            ':fid' => $funcId,
            ':ini' => $dataInicio,
            ':fim' => $dataFim
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
