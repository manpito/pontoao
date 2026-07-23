<?php
$file = 'app/Controllers/ExportacaoController.php';
$content = file_get_contents($file);

$search = <<<'SEARCH'
                // Atrasos e Horas Extra
                $mDia = $marcPorDia[$dataStr] ?? [];
                $turno = $escalaService->calcularTurnoEm($fId, $dataStr);

                if ($turno) {
                    $entrada = null; $saida = null; $minutosIntervalo = 0; $iniInt = null;
                    foreach ($mDia as $m) {
                        $ts = strtotime($m['data_hora']);
                        if ($m['tipo'] === 'entrada' && !$entrada) $entrada = $ts;
                        elseif ($m['tipo'] === 'saida') $saida = $ts;
                        elseif ($m['tipo'] === 'inicio_intervalo') $iniInt = $ts;
                        elseif ($m['tipo'] === 'fim_intervalo' && $iniInt) {
                            $minutosIntervalo += (int) round(($ts - $iniInt) / 60);
                            $iniInt = null;
                        }
                    }

                    if ($entrada) {
                        // Atrasos
                        if ($turno['tipo'] !== 'folga' && $turno['hora_entrada']) {
                            $tolerancia = $turno['tolerancia_entrada_min'] ?? 10;
                            $entradaPrevista = strtotime($dataStr . ' ' . $turno['hora_entrada']);
                            $atrasoMinutos = (int) round(($entrada - $entradaPrevista) / 60);
                            if ($atrasoMinutos > $tolerancia) {
                                $linhas[] = $this->formatarLinhaPrimavera('F', $codFunc, $dataStr, 'F07', ($atrasoMinutos - $tolerancia) / 60);
                            }
                        }

                        // Horas Extra
                        $horasEfectivas = ($turno['tipo'] === 'folga') ? 0 : ($turno['horas_efectivas'] ?? 8);
                        $saidaCalc = $saida ?? ($entrada + ($horasEfectivas * 3600));
                        $minutosTrabalhados = (int) round(($saidaCalc - $entrada) / 60) - $minutosIntervalo;
                        $minutosEsperados   = (int) ($horasEfectivas * 60);
                        $minutosExtra = max(0, $minutosTrabalhados - $minutosEsperados);

                        if ($minutosExtra > 0) {
                            $codigo = ($diaSemana >= 6 || isset($feriados[$dataStr])) ? 'H04' : 'H02';
                            $linhas[] = $this->formatarLinhaPrimavera('H', $codFunc, $dataStr, $codigo, $minutosExtra / 60);
                        }
                    }
                }
SEARCH;

$replace = <<<'REPLACE'
                // Atrasos e Horas Extra
                $mDia = $marcPorDia[$dataStr] ?? [];
                $turno = $escalaService->calcularTurnoEm($fId, $dataStr);

                $tipoDia = 'util';
                if (isset($feriados[$dataStr])) {
                    $tipoDia = 'feriado';
                } elseif ($diaSemana === 6) {
                    $tipoDia = 'sabado';
                } elseif ($diaSemana === 7) {
                    $tipoDia = 'domingo';
                }

                // Na exportacao não temos o regime da escala de imediato carregado p/ todo o lado,
                // para simplificar assumimos regime='normal' para fins de H04, pois Exportacao Primavera trata fds/feriado como H04 direto na lógica legada:
                // `$codigo = ($diaSemana >= 6 || isset($feriados[$dataStr])) ? 'H04' : 'H02';`
                // No entanto, para ser correto e suportar futuro, procuramos o regime do func ou defaultamos
                $regimeEscala = 'normal'; // default legada

                $calculoService = new \App\Services\CalculoHorasService();
                $resultadoDia = $calculoService->calcularDia($mDia, $turno, $tipoDia, $regimeEscala, $dataStr);

                if ($resultadoDia['atraso_minutos'] > 0) {
                    $linhas[] = $this->formatarLinhaPrimavera('F', $codFunc, $dataStr, 'F07', $resultadoDia['atraso_minutos'] / 60);
                }

                if ($resultadoDia['minutos_extra'] > 0) {
                    $linhas[] = $this->formatarLinhaPrimavera('H', $codFunc, $dataStr, 'H02', $resultadoDia['minutos_extra'] / 60);
                }

                if ($resultadoDia['minutos_extra_extraordinario'] > 0) {
                    $linhas[] = $this->formatarLinhaPrimavera('H', $codFunc, $dataStr, 'H04', $resultadoDia['minutos_extra_extraordinario'] / 60);
                }
REPLACE;

$newContent = str_replace($search, $replace, $content);
file_put_contents($file, $newContent);
echo "Replaced in ExportacaoController.php\n";
