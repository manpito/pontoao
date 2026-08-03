<?php
$content = file_get_contents('config/routes.php');
$search = "    \$group->get('/relatorios/marcacoes-diarias/{funcionario_id}', [\App\Controllers\RelatorioController::class, 'marcacoesDiarias'])\n          ->add(AuthMiddleware::class);";
$replace = "    \$group->get('/relatorios/marcacoes-diarias', [\App\Controllers\RelatorioController::class, 'marcacoesDiarias'])\n          ->add(AuthMiddleware::class);\n\n    \$group->get('/relatorios/marcacoes-diarias/{funcionario_id}', [\App\Controllers\RelatorioController::class, 'marcacoesDiarias'])\n          ->add(AuthMiddleware::class);";
$content = str_replace($search, $replace, $content);
file_put_contents('config/routes.php', $content);
