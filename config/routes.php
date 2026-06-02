<?php

declare(strict_types=1);

use App\Controllers\Admin\SuperAdminController;
use App\Controllers\Admin\TenantController;
use App\Controllers\Auth\AuthController;
use App\Controllers\CargoController;
use App\Controllers\Auth\SuperAdminAuthController;
use App\Middleware\AuthMiddleware;
use App\Middleware\SuperAdminMiddleware;
use App\Middleware\TenantMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Config\TenantResolver;

// ============================================================
// SUPER-ADMIN PANEL (admin.tuaplataforma.ao)
// ============================================================

$app->group('/super-admin', function (\Slim\Routing\RouteCollectorProxy $group) {

    // Autenticação super-admin
    $group->post('/auth/login',   [SuperAdminAuthController::class, 'login'])
          ->add(new RateLimitMiddleware(maxRequests: 5, windowSeconds: 60));

    $group->post('/auth/logout',  [SuperAdminAuthController::class, 'logout'])
          ->add(SuperAdminMiddleware::class);

    $group->post('/auth/refresh', [SuperAdminAuthController::class, 'refresh']);

    // Dashboard
    $group->get('/dashboard',     [SuperAdminController::class, 'dashboard'])
          ->add(SuperAdminMiddleware::class);

    // Gestão de tenants
    $group->get('/tenants',                [TenantController::class, 'index'])
          ->add(SuperAdminMiddleware::class);

    $group->post('/tenants',               [TenantController::class, 'store'])
          ->add(SuperAdminMiddleware::class);

    $group->get('/tenants/{id}',           [TenantController::class, 'show'])
          ->add(SuperAdminMiddleware::class);

    $group->put('/tenants/{id}',           [TenantController::class, 'update'])
          ->add(SuperAdminMiddleware::class);

    $group->post('/tenants/{id}/suspend',  [TenantController::class, 'suspend'])
          ->add(SuperAdminMiddleware::class);

    $group->post('/tenants/{id}/activate', [TenantController::class, 'activate'])
          ->add(SuperAdminMiddleware::class);

    $group->delete('/tenants/{id}',        [TenantController::class, 'destroy'])
          ->add(SuperAdminMiddleware::class);

    // Log de auditoria
    $group->get('/audit-log',     [SuperAdminController::class, 'auditLog'])
          ->add(SuperAdminMiddleware::class);

    $group->get('/planos',        [SuperAdminController::class, 'planos'])
          ->add(SuperAdminMiddleware::class);

});

// ============================================================
// TENANT API (empresa.tuaplataforma.ao/...)
// ============================================================

$app->group('/api', function (\Slim\Routing\RouteCollectorProxy $group) {

    // --- Autenticação tenant ---
    $group->post('/auth/login',   [AuthController::class, 'login'])
          ->add(new RateLimitMiddleware(maxRequests: 5, windowSeconds: 60));

    $group->post('/auth/logout',  [AuthController::class, 'logout'])
          ->add(AuthMiddleware::class);

    $group->post('/auth/refresh', [AuthController::class, 'refresh']);

    $group->get('/auth/me',       [AuthController::class, 'me'])
          ->add(AuthMiddleware::class);

    // --- Funcionários ---
    $group->get('/funcionarios',        [\App\Controllers\FuncionarioController::class, 'index'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador', 'supervisor']));

    $group->post('/funcionarios',       [\App\Controllers\FuncionarioController::class, 'store'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    $group->get('/funcionarios/{id}',   [\App\Controllers\FuncionarioController::class, 'show'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));

    $group->put('/funcionarios/{id}',   [\App\Controllers\FuncionarioController::class, 'update'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    $group->delete('/funcionarios/{id}',[\App\Controllers\FuncionarioController::class, 'destroy'])
          ->add(AuthMiddleware::role(['super_admin_tenant']));


    // --- Cargos ---
    $group->get('/cargos',            [CargoController::class, 'index'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));

    $group->post('/cargos',           [CargoController::class, 'store'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    $group->put('/cargos/{id}',       [CargoController::class, 'update'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    $group->delete('/cargos/{id}',    [CargoController::class, 'destroy'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    // --- Departamentos ---
    $group->get('/departamentos',       [\App\Controllers\DepartamentoController::class, 'index'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));

    $group->post('/departamentos',      [\App\Controllers\DepartamentoController::class, 'store'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    $group->put('/departamentos/{id}',  [\App\Controllers\DepartamentoController::class, 'update'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    $group->delete('/departamentos/{id}',[\App\Controllers\DepartamentoController::class, 'destroy'])
          ->add(AuthMiddleware::role(['super_admin_tenant']));

    // --- Horários ---
    $group->get('/horarios',            [\App\Controllers\HorarioController::class, 'index'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));

    $group->post('/horarios',           [\App\Controllers\HorarioController::class, 'store'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    $group->put('/horarios/{id}',       [\App\Controllers\HorarioController::class, 'update'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    // --- Marcações ---
    $group->get('/marcacoes',           [\App\Controllers\MarcacaoController::class, 'index'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador', 'supervisor']));

    $group->post('/marcacoes',          [\App\Controllers\MarcacaoController::class, 'store'])
          ->add(AuthMiddleware::class);

    $group->put('/marcacoes/{id}',      [\App\Controllers\MarcacaoController::class, 'update'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    // Endpoint para receber marcações do zk-bridge (autenticado por API key, não por JWT)
    $group->post('/zk-bridge/marcacoes', [\App\Controllers\ZkBridgeController::class, 'receive'])
          ->add(\App\Middleware\ZkBridgeAuthMiddleware::class);
$group->get('/zk-bridge/ping',         [\App\Controllers\ZkBridgeController::class, 'ping'])
      ->add(\App\Middleware\ZkBridgeAuthMiddleware::class);

$group->get('/zk-bridge/utilizadores', [\App\Controllers\ZkBridgeController::class, 'listarUtilizadores'])
      ->add(\App\Middleware\ZkBridgeAuthMiddleware::class);

$group->post('/zk-bridge/relogios',    [\App\Controllers\ZkBridgeController::class, 'registarRelogio'])
      ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    // --- Relatórios ---
    $group->get('/relatorios/assiduidade',  [\App\Controllers\RelatorioController::class, 'assiduidade'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));

    $group->get('/relatorios/horas',        [\App\Controllers\RelatorioController::class, 'horas'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));

    // --- Férias ---
    $group->get('/ferias',              [\App\Controllers\FeriasController::class, 'index'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador', 'supervisor']));

    $group->post('/ferias',             [\App\Controllers\FeriasController::class, 'store'])
          ->add(AuthMiddleware::class);

    $group->put('/ferias/{id}/aprovar', [\App\Controllers\FeriasController::class, 'aprovar'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'supervisor']));

    // --- Feriados ---
    $group->get('/feriados',            [\App\Controllers\FeriadoController::class, 'index'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));

    $group->post('/feriados',           [\App\Controllers\FeriadoController::class, 'store'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    $group->put('/feriados/{id}',        [\App\Controllers\FeriadoController::class, 'update'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    $group->delete('/feriados/{id}',     [\App\Controllers\FeriadoController::class, 'destroy'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    // --- Configurações ---
    $group->get('/configuracoes',       [\App\Controllers\ConfiguracaoController::class, 'index'])
          ->add(AuthMiddleware::role(['super_admin_tenant']));

    $group->put('/configuracoes',       [\App\Controllers\ConfiguracaoController::class, 'update'])
          ->add(AuthMiddleware::role(['super_admin_tenant']));

    // --- Notificações ---
    $group->get('/notificacoes',        [\App\Controllers\NotificacaoController::class, 'index'])
          ->add(AuthMiddleware::class);

    $group->put('/notificacoes/{id}/ler', [\App\Controllers\NotificacaoController::class, 'marcarLida'])
          ->add(AuthMiddleware::class);

    // --- Turnos ---
    $group->get("/turnos",                    [\App\Controllers\TurnoController::class, "index"])
          ->add(AuthMiddleware::class);

    $group->post("/turnos",                   [\App\Controllers\TurnoController::class, "store"])
          ->add(AuthMiddleware::role(["super_admin_tenant", "rh_manager"]));

    $group->put("/turnos/{id}",               [\App\Controllers\TurnoController::class, "update"])
          ->add(AuthMiddleware::role(["super_admin_tenant", "rh_manager"]));

    $group->delete("/turnos/{id}",            [\App\Controllers\TurnoController::class, "destroy"])
          ->add(AuthMiddleware::role(["super_admin_tenant", "rh_manager"]));

    // --- Escalas ---
    $group->get("/escalas",                                 [\App\Controllers\EscalaController::class, "index"])
          ->add(AuthMiddleware::class);

    $group->post("/escalas",                                [\App\Controllers\EscalaController::class, "store"])
          ->add(AuthMiddleware::role(["super_admin_tenant", "rh_manager"]));

    $group->get("/escalas/{id}",                            [\App\Controllers\EscalaController::class, "show"])
          ->add(AuthMiddleware::class);

    $group->put("/escalas/{id}",                            [\App\Controllers\EscalaController::class, "update"])
          ->add(AuthMiddleware::role(["super_admin_tenant", "rh_manager"]));

    $group->delete("/escalas/{id}",                         [\App\Controllers\EscalaController::class, "destroy"])
          ->add(AuthMiddleware::role(["super_admin_tenant", "rh_manager"]));

    $group->post("/escalas/{id}/turnos",                    [\App\Controllers\EscalaController::class, "adicionarTurno"])
          ->add(AuthMiddleware::role(["super_admin_tenant", "rh_manager"]));

    $group->delete("/escalas/{id}/turnos/{posicao}",        [\App\Controllers\EscalaController::class, "removerTurno"])
          ->add(AuthMiddleware::role(["super_admin_tenant", "rh_manager"]));

    $group->get("/escalas/{id}/atribuicoes",                [\App\Controllers\EscalaController::class, "listarAtribuicoes"])
          ->add(AuthMiddleware::class);

    $group->post("/escalas/{id}/atribuicoes",               [\App\Controllers\EscalaController::class, "atribuir"])
          ->add(AuthMiddleware::role(["super_admin_tenant", "rh_manager"]));

    $group->delete("/escalas/{id}/atribuicoes/{funcionario_id}", [\App\Controllers\EscalaController::class, "removerAtribuicao"])
          ->add(AuthMiddleware::role(["super_admin_tenant", "rh_manager"]));

    // --- Exportação ---
    $group->get('/exportacao/primavera',        [\App\Controllers\ExportacaoController::class, 'primavera'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));

    $group->get('/exportacao/funcionarios',     [\App\Controllers\ExportacaoController::class, 'funcionarios'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));

    $group->get('/exportacao/template-importacao', [\App\Controllers\ExportacaoController::class, 'templateImportacao'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));

    // --- Importação ---
    $group->post('/importacao/validar',         [\App\Controllers\ImportacaoController::class, 'validar'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

    $group->post('/importacao/funcionarios',    [\App\Controllers\ImportacaoController::class, 'funcionarios'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

// --- Acesso Portal ---
$group->get('/funcionarios/{id}/acesso-portal',        [\App\Controllers\FuncionarioController::class, 'estadoAcessoPortal'])
      ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

$group->post('/funcionarios/{id}/acesso-portal',       [\App\Controllers\FuncionarioController::class, 'criarAcessoPortal'])
      ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

$group->put('/funcionarios/{id}/acesso-portal/toggle', [\App\Controllers\FuncionarioController::class, 'toggleAcessoPortal'])
      ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

// --- Saldo Férias ---
$group->get('/ferias/saldo/{funcionario_id}',          [\App\Controllers\FeriasController::class, 'saldo'])
      ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador', 'supervisor']));

$group->put('/ferias/{funcionario_id}/ajustar',        [\App\Controllers\FeriasController::class, 'ajustarPorFaltas'])
      ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));

// --- Exportação Relatórios ---
$group->get('/relatorios/assiduidade/exportar',        [\App\Controllers\RelatorioController::class, 'exportarAssiduidade'])
      ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));

$group->get('/relatorios/horas/exportar',              [\App\Controllers\RelatorioController::class, 'exportarHoras'])
      ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));

    // Períodos mensais
    $group->get('/periodos',                    [\App\Controllers\PeriodoController::class, 'index'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));
    $group->post('/periodos/fechar',              [\App\Controllers\PeriodoController::class, 'fechar'])
          ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager']));
    $group->post('/periodos/abrir',               [\App\Controllers\PeriodoController::class, 'abrir'])
          ->add(AuthMiddleware::role(['super_admin_tenant']));
    $group->post('/periodos/configurar',           [\App\Controllers\PeriodoController::class, 'configurar'])
          ->add(AuthMiddleware::role(['super_admin_tenant']));
})->add(TenantMiddleware::tenantOnly());

// ============================================================
// PORTAL WEB (frontend SPA para o tenant — servido pelo PHP)
// ============================================================
// ADMS ZKTeco — fora do grupo /api

$app->get('/iclock/cdata',      [\App\Controllers\ZkBridgeController::class, 'admsCdata'])
    ;

$app->post('/iclock/cdata',     [\App\Controllers\ZkBridgeController::class, 'admsPostCdata'])
    ;

$app->get('/iclock/getrequest', [\App\Controllers\ZkBridgeController::class, 'admsGetRequest'])
    ;

$app->post('/iclock/devicecmd', [\App\Controllers\ZkBridgeController::class, 'admsDeviceCmd'])
    ;

$app->get('/ponto[/{params:.*}]', function ($request, $response) {
    $file = __DIR__ . '/../public/ponto.html';
    if (file_exists($file)) {
        $response->getBody()->write(file_get_contents($file));
    }
    return $response->withHeader('Content-Type', 'text/html');
});
// Servir o site oficial na raiz
$app->get('/', function ($request, $response) {
    $file = __DIR__ . '/../index.html';
    if (file_exists($file)) {
        $response->getBody()->write(file_get_contents($file));
        return $response->withHeader('Content-Type', 'text/html');
    }
    return $response->withStatus(404);
});
