<?php

$file = 'app/Controllers/RelatorioController.php';
$content = file_get_contents($file);

$search = <<<'SEARCH'
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
SEARCH;

$replace = <<<'REPLACE'
            $tipoDia = 'util';
            if (isset($feriados[$dataStr])) {
                $tipoDia = 'feriado';
            } elseif ($diaSemana === 6) {
                $tipoDia = 'sabado';
            } elseif ($diaSemana === 7) {
                $tipoDia = 'domingo';
            }

            // Manter arredondamento automático antes do cálculo do serviço
            if ($turno && $turno['tipo'] !== 'folga' && $turno['hora_saida'] && $ultimaSaida) {
                $horaCorteSaida = substr($turno['hora_saida'], 0, 5);
                if ($ultimaSaida > $horaCorteSaida) {
                    $tsCorte = strtotime($dataStr . ' ' . $horaCorteSaida . ($turno['atravessa_dia_civil'] ? ' +1 day' : ''));
                    $tsReal  = strtotime($dataStr . ' ' . $ultimaSaida . ($turno['atravessa_dia_civil'] ? ' +1 day' : ''));
                    $minutosExtraReais = (int) round(($tsReal - $tsCorte) / 60);
                    $minutosAprovados = $horasExtraAprovadas[$dataStr] ?? 0;

                    if ($minutosExtraReais > $minutosAprovados) {
                        $novaHoraSaida = date('H:i', strtotime($horaCorteSaida . " +{$minutosAprovados} minutes"));

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

                        // Necessário atualizar a array $mDia para o calculo
                        foreach ($mDia as &$mRef) {
                            if ($mRef['tipo'] === 'saida' && substr($mRef['data_hora'], 11, 5) > $horaCorteSaida) {
                                $mRef['data_hora'] = ($turno['atravessa_dia_civil'] && $novaHoraSaida < '12:00' ? date('Y-m-d', strtotime($dataStr . ' +1 day')) : $dataStr) . ' ' . $novaHoraSaida . ':00';
                            }
                        }
                        unset($mRef);
                    }
                }
            }

            $calculoService = new \App\Services\CalculoHorasService();
            $resultadoDia = $calculoService->calcularDia($mDia, $turno, $tipoDia, $regimeEscala, $dataStr);

            $minutosEsperados = 0;
            if ($turno && $turno['tipo'] !== 'folga' && $turno['horas_efectivas']) {
                $minutosEsperados = (int) round((float)$turno['horas_efectivas'] * 60);
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
                    'atraso_minutos'            => $resultadoDia['atraso_minutos'],
                    'saida_antecipada_minutos'  => $resultadoDia['saida_antecipada_minutos'],
                    'total_horas'               => $resultadoDia['horas_trabalhadas'],
                    'minutos_esperados'              => $minutosEsperados,
                    'minutos_extra'                  => $resultadoDia['minutos_extra'],
                    'minutos_extra_extraordinario'   => $resultadoDia['minutos_extra_extraordinario'],
                ]
            ];
REPLACE;

$newContent = str_replace($search, $replace, $content);
file_put_contents($file, $newContent);
echo "Replaced in RelatorioController.php\n";
