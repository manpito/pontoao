<?php
$code = file_get_contents('app/Controllers/RelatorioController.php');

$searchExcel = <<<EOT
        } elseif (\$tipo === 'marcacoes_diarias') {
            \$f = \$dados['funcionario'];
            \$sheet->setCellValue('A1', 'Relatório de Marcações Diárias');
            \$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

            \$sheet->setCellValue('A3', 'Funcionário:'); \$sheet->setCellValue('B3', \$f['nome']);
            \$sheet->setCellValue('A4', 'Número:');      \$sheet->setCellValue('B4', \$f['numero']);
            \$sheet->setCellValue('A5', 'Período:');      \$sheet->setCellValue('B5', \$dados['periodo']['inicio'] . ' a ' . \$dados['periodo']['fim']);
            \$sheet->getStyle('A3:A5')->getFont()->setBold(true);

            \$sheet->fromArray(['Data', 'Dia', 'Hora', 'Tipo', 'Origem'], NULL, 'A7');
            \$sheet->getStyle('A7:E7')->applyFromArray(\$headerStyle);

            \$row = 8;
            foreach (\$dados['dias'] as \$d) {
                if (empty(\$d['marcacoes'])) {
                    \$sheet->fromArray([\$d['data'], \$d['dia_semana'], 'Sem marcações'], NULL, 'A' . \$row);
                    \$row++;
                    continue;
                }
                foreach (\$d['marcacoes'] as \$idx => \$m) {
                    \$sheet->fromArray([
                        \$idx === 0 ? \$d['data'] : '',
                        \$idx === 0 ? \$d['dia_semana'] : '',
                        \$m['hora'],
                        \$m['tipo'],
                        \$m['origem']
                    ], NULL, 'A' . \$row);
                    \$row++;
                }
                \$sheet->setCellValue('C' . \$row, 'Resumo:');
                \$priEnt = \$d['resumo']['primeira_entrada'] ?? '--:--';
                \$ultSai = \$d['resumo']['ultima_saida'] ?? '--:--';
                \$totH = \$d['resumo']['total_horas'];
                \$sheet->setCellValue('D' . \$row, "Ent: {\$priEnt} | Sai: {\$ultSai} | Total: {\$totH}h");
                \$sheet->getStyle("C\$row:D\$row")->getFont()->setItalic(true)->setSize(9);
                \$row++;
            }
        }
EOT;

$replaceExcel = <<<EOT
        } elseif (\$tipo === 'marcacoes_diarias') {
            \$sheet->setCellValue('A1', 'Relatório de Marcações Diárias');
            \$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

            \$relatorios = \$dados['relatorios'] ?? [\$dados];

            \$row = 3;
            foreach (\$relatorios as \$relatorio) {
                \$f = \$relatorio['funcionario'];

                \$sheet->setCellValue('A' . \$row, 'Funcionário:'); \$sheet->setCellValue('B' . \$row, \$f['nome']);
                \$sheet->setCellValue('A' . (\$row+1), 'Número:');      \$sheet->setCellValue('B' . (\$row+1), \$f['numero']);
                \$sheet->setCellValue('A' . (\$row+2), 'Período:');      \$sheet->setCellValue('B' . (\$row+2), \$relatorio['periodo']['inicio'] . ' a ' . \$relatorio['periodo']['fim']);
                \$sheet->getStyle('A' . \$row . ':A' . (\$row+2))->getFont()->setBold(true);

                \$row += 4;
                \$sheet->fromArray(['Data', 'Dia', 'Hora', 'Tipo', 'Origem'], NULL, 'A' . \$row);
                \$sheet->getStyle('A' . \$row . ':E' . \$row)->applyFromArray(\$headerStyle);
                \$row++;

                foreach (\$relatorio['dias'] as \$d) {
                    if (empty(\$d['marcacoes'])) {
                        \$sheet->fromArray([\$d['data'], \$d['dia_semana'], 'Sem marcações'], NULL, 'A' . \$row);
                        \$row++;
                        continue;
                    }
                    foreach (\$d['marcacoes'] as \$idx => \$m) {
                        \$sheet->fromArray([
                            \$idx === 0 ? \$d['data'] : '',
                            \$idx === 0 ? \$d['dia_semana'] : '',
                            \$m['hora'],
                            \$m['tipo'],
                            \$m['origem']
                        ], NULL, 'A' . \$row);
                        \$row++;
                    }
                    \$sheet->setCellValue('C' . \$row, 'Resumo:');
                    \$priEnt = \$d['resumo']['primeira_entrada'] ?? '--:--';
                    \$ultSai = \$d['resumo']['ultima_saida'] ?? '--:--';
                    \$totH = \$d['resumo']['total_horas'];
                    \$sheet->setCellValue('D' . \$row, "Ent: {\$priEnt} | Sai: {\$ultSai} | Total: {\$totH}h");
                    \$sheet->getStyle("C\$row:D\$row")->getFont()->setItalic(true)->setSize(9);
                    \$row++;
                }
                \$row += 2; // Space between employees
            }
        }
EOT;

$code = str_replace($searchExcel, $replaceExcel, $code);


$searchCSV = <<<EOT
        } else {
            fputcsv(\$output, ['Nº', 'Nome', 'Departamento', 'H. Efectivas', 'H. Esperadas', 'H. Extra', 'H. Défice', 'Atrasos (min)', 'Saldo (h)'], ';');
            foreach (\$dados as \$r) {
                \$s = \$r['resumo'];
                fputcsv(\$output, [
                    \$r['funcionario']['numero'],
                    \$r['funcionario']['nome'],
                    \$r['funcionario']['departamento'] ?? '',
                    \$s['horas_efectivas'],
                    \$s['horas_esperadas'],
                    \$s['horas_extra'],
                    \$s['horas_deficit'],
                    \$s['minutos_atraso_total'],
                    \$s['saldo_horas'],
                ], ';');
            }
        }
EOT;

$replaceCSV = <<<EOT
        } elseif (\$tipo === 'marcacoes_diarias') {
            \$relatorios = \$dados['relatorios'] ?? [\$dados];
            foreach (\$relatorios as \$relatorio) {
                \$f = \$relatorio['funcionario'];
                fputcsv(\$output, ['Funcionário:', \$f['nome']], ';');
                fputcsv(\$output, ['Número:', \$f['numero']], ';');
                fputcsv(\$output, ['Período:', \$relatorio['periodo']['inicio'] . ' a ' . \$relatorio['periodo']['fim']], ';');
                fputcsv(\$output, [], ';');

                fputcsv(\$output, ['Data', 'Dia', 'Hora', 'Tipo', 'Origem'], ';');

                foreach (\$relatorio['dias'] as \$d) {
                    if (empty(\$d['marcacoes'])) {
                        fputcsv(\$output, [\$d['data'], \$d['dia_semana'], 'Sem marcações'], ';');
                        continue;
                    }
                    foreach (\$d['marcacoes'] as \$idx => \$m) {
                        fputcsv(\$output, [
                            \$idx === 0 ? \$d['data'] : '',
                            \$idx === 0 ? \$d['dia_semana'] : '',
                            \$m['hora'],
                            \$m['tipo'],
                            \$m['origem']
                        ], ';');
                    }

                    \$priEnt = \$d['resumo']['primeira_entrada'] ?? '--:--';
                    \$ultSai = \$d['resumo']['ultima_saida'] ?? '--:--';
                    \$totH = \$d['resumo']['total_horas'];
                    fputcsv(\$output, ['', '', 'Resumo:', "Ent: {\$priEnt} | Sai: {\$ultSai} | Total: {\$totH}h"], ';');
                }
                fputcsv(\$output, [], ';');
                fputcsv(\$output, [], ';');
            }
        } else {
            fputcsv(\$output, ['Nº', 'Nome', 'Departamento', 'H. Efectivas', 'H. Esperadas', 'H. Extra', 'H. Défice', 'Atrasos (min)', 'Saldo (h)'], ';');
            foreach (\$dados as \$r) {
                \$s = \$r['resumo'];
                fputcsv(\$output, [
                    \$r['funcionario']['numero'],
                    \$r['funcionario']['nome'],
                    \$r['funcionario']['departamento'] ?? '',
                    \$s['horas_efectivas'],
                    \$s['horas_esperadas'],
                    \$s['horas_extra'],
                    \$s['horas_deficit'],
                    \$s['minutos_atraso_total'],
                    \$s['saldo_horas'],
                ], ';');
            }
        }
EOT;
$code = str_replace($searchCSV, $replaceCSV, $code);
file_put_contents('app/Controllers/RelatorioController.php', $code);
