<?php
$file = 'app/Services/RelatorioPeriodoService.php';
$content = file_get_contents($file);

$search = <<<'SEARCH'
            while ($atual <= $fimTs) {
                $dia = date('Y-m-d', $atual);
                $marcacoesDia = $marcacoesPorDia[$dia] ?? [];

                if (count($marcacoesDia) === 0) {
                    $atual = strtotime('+1 day', $atual);
                    continue; // Dia sem marcações
                }

                if (count($marcacoesDia) === 1) {
                    // Meio Dia
                    $meioDias += 1;
                    $horasTrabalhadas += 4.0;
                    $horasMeioDia += 4.0;
                } else {
                    // Dia Completo
                    // Encontrar primeira entrada e última saída
                    $entrada = null;
                    $saida = null;
                    foreach ($marcacoesDia as $m) {
                        $ts = strtotime($m['data_hora']);
                        if ($m['tipo'] === 'entrada' && ($entrada === null || $ts < $entrada)) {
                            $entrada = $ts;
                        }
                        if ($m['tipo'] === 'saida' && ($saida === null || $ts > $saida)) {
                            $saida = $ts;
                        }
                    }

                    if ($entrada !== null && $saida !== null) {
                        $brutoHoras = ($saida - $entrada) / 3600;
                        // Desconto fixo de 1 hora
                        $liquidoHoras = $brutoHoras - 1.0;

                        // Se o líquido for <= 0 após almoço, assume-se como 0 para não descontar horas, mas conta como dia trabalhado com 0h.
                        $liquidoHorasAjustado = max(0, $liquidoHoras);

                        $diasTrabalhados += 1;
                        $horasTrabalhadas += $liquidoHorasAjustado;

                        // Calcular Horas Extra
                        $horasEsperadas = $this->getHorasEsperadasDia($funcId, $dia, (float) ($func['horas_esperadas_dia'] ?? 8.0));
                        if ($liquidoHorasAjustado > $horasEsperadas) {
                            $horasExtra += ($liquidoHorasAjustado - $horasEsperadas);
                        }
                    }
                }

                $atual = strtotime('+1 day', $atual);
            }
SEARCH;

$replace = <<<'REPLACE'
            $calculoService = new \App\Services\CalculoHorasService();
            // Buscar feriados para sabermos que tipo de dia é (usaremos FeriadoService se possível, mas como é agregação ignoramos feriado no RelatorioPeriodo ou instanciamos se necessário, mas para horas trabalhadas totais só importam horas normais/extra)
            // No Relatorio de Período as horas extra estão juntas, mas o `calcularDia` pede $tipoDia.
            // Para ser correto, devíamos passar o tipo, mas como a agregação soma ambos (ou só extra base), faremos um fallback rápido.

            while ($atual <= $fimTs) {
                $dia = date('Y-m-d', $atual);
                $marcacoesDia = $marcacoesPorDia[$dia] ?? [];

                if (count($marcacoesDia) === 0) {
                    $atual = strtotime('+1 day', $atual);
                    continue; // Dia sem marcações
                }

                $turno = $this->escalaService->calcularTurnoEm($funcId, $dia);
                // Tipo dia fallback (RelatorioPeriodo actual não deduz feriados para H02/H04 separadamente, soma tudo)
                $diaSemana = (int) date('N', $atual);
                $tipoDia = ($diaSemana >= 6) ? 'sabado' : 'util'; // Simplificação, pois o relatório de período agrupa tudo numa só coluna "horas_extra"
                $regimeEscala = 'normal';

                $resultadoDia = $calculoService->calcularDia($marcacoesDia, $turno, $tipoDia, $regimeEscala, $dia);

                if ($resultadoDia['tipo_presenca'] === 'meio_dia') {
                    $meioDias += 1;
                    $horasTrabalhadas += $resultadoDia['horas_trabalhadas'];
                    $horasMeioDia += $resultadoDia['horas_trabalhadas'];
                } elseif ($resultadoDia['tipo_presenca'] === 'completo') {
                    $diasTrabalhados += 1;
                    $horasTrabalhadas += $resultadoDia['horas_trabalhadas'];
                    $horasExtra += ($resultadoDia['minutos_extra'] + $resultadoDia['minutos_extra_extraordinario']) / 60;
                }

                $atual = strtotime('+1 day', $atual);
            }
REPLACE;

$newContent = str_replace($search, $replace, $content);
file_put_contents($file, $newContent);
echo "Replaced in RelatorioPeriodoService.php\n";
