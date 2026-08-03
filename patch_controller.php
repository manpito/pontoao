<?php

$code = file_get_contents('app/Controllers/RelatorioController.php');

$search = <<<EOT
    public function marcacoesDiarias(ServerRequestInterface \$request, ResponseInterface \$response, array \$args): ResponseInterface
    {
        \$tenantInfo = \$this->getTenantInfo();
        \$user   = \$request->getAttribute('auth_user');
        \$perfil = \$request->getAttribute('auth_perfil');
        \$funcId = (int) \$args['funcionario_id'];

        // RBAC: admin e rh vêem qualquer funcionário. Supervisor vê apenas funcionários da sua equipa. Funcionário vê apenas o seu próprio relatório.
        if (\$perfil === 'funcionario' && \$user->funcionario_id != \$funcId) {
            return \$this->json(\$response, 403, ['erro' => true, 'mensagem' => 'Sem permissão para ver este relatório.']);
        }

        \$params     = \$request->getQueryParams();
        \$dataInicio = \$params['inicio'] ?? date('Y-m-01');
        \$dataFim    = \$params['fim']    ?? date('Y-m-t');
        \$formato    = \$params['formato'] ?? 'json';

        \$db = \$this->db();

        // 1. Dados do funcionário
EOT;

$replace = <<<EOT
    private function gerarDadosMarcacoesDiarias(PDO \$db, int \$funcId, string \$dataInicio, string \$dataFim, string \$perfil, \$user, array \$tenantInfo): ?array
    {
        // RBAC: admin e rh vêem qualquer funcionário. Supervisor vê apenas funcionários da sua equipa. Funcionário vê apenas o seu próprio relatório.
        if (\$perfil === 'funcionario' && \$user->funcionario_id != \$funcId) {
            return null; // Return null to indicate unauthorized
        }

        // 1. Dados do funcionário
EOT;

$code = str_replace($search, $replace, $code);

$search2 = <<<EOT
        if (!\$func) {
            return \$this->json(\$response, 404, ['erro' => true, 'mensagem' => 'Funcionário não encontrado.']);
        }

        if (\$perfil === 'supervisor' && \$func['supervisor_id'] != \$user->funcionario_id && \$func['id'] != \$user->funcionario_id) {
            return \$this->json(\$response, 403, ['erro' => true, 'mensagem' => 'Sem permissão para ver este relatório (não pertence à sua equipa).']);
        }
EOT;

$replace2 = <<<EOT
        if (!\$func) {
            return null; // Funcionário não encontrado.
        }

        if (\$perfil === 'supervisor' && \$func['supervisor_id'] != \$user->funcionario_id && \$func['id'] != \$user->funcionario_id) {
            return null; // Sem permissão para ver este relatório (não pertence à sua equipa).
        }
EOT;
$code = str_replace($search2, $replace2, $code);

$search3 = <<<EOT
        \$dados = [
            'empresa' => ['nome' => \$tenantInfo['nome_empresa'], 'nif' => \$tenantInfo['nif']],
            'funcionario' => [
                'id' => (int) \$func['id'],
                'nome' => \$func['nome_completo'],
                'numero' => \$func['numero_funcionario'],
                'departamento' => \$func['departamento'],
                'cargo' => \$func['cargo']
            ],
            'periodo' => ['inicio' => \$dataInicio, 'fim' => \$dataFim],
            'dias' => \$dias
        ];

        if (\$formato === 'xlsx') {
            return \$this->exportarExcel(\$dados, 'marcacoes_diarias', "relatorio_marcacoes_{\$func['numero_funcionario']}", \$response);
        }

        return \$this->json(\$response, 200, \$dados);
    }
EOT;

$replace3 = <<<EOT
        return [
            'empresa' => ['nome' => \$tenantInfo['nome_empresa'], 'nif' => \$tenantInfo['nif']],
            'funcionario' => [
                'id' => (int) \$func['id'],
                'nome' => \$func['nome_completo'],
                'numero' => \$func['numero_funcionario'],
                'departamento' => \$func['departamento'],
                'cargo' => \$func['cargo']
            ],
            'periodo' => ['inicio' => \$dataInicio, 'fim' => \$dataFim],
            'dias' => \$dias
        ];
    }

    public function marcacoesDiarias(ServerRequestInterface \$request, ResponseInterface \$response, array \$args): ResponseInterface
    {
        \$tenantInfo = \$this->getTenantInfo();
        \$user   = \$request->getAttribute('auth_user');
        \$perfil = \$request->getAttribute('auth_perfil');
        \$funcIdArg = \$args['funcionario_id'] ?? null;

        \$params     = \$request->getQueryParams();
        \$dataInicio = \$params['inicio'] ?? date('Y-m-01');
        \$dataFim    = \$params['fim']    ?? date('Y-m-t');
        \$formato    = \$params['formato'] ?? 'json';
        \$funcionarioIdsParam = \$params['funcionario_ids'] ?? '';

        \$db = \$this->db();

        \$funcionarioIds = [];
        if (\$funcIdArg !== null) {
            \$funcionarioIds[] = (int) \$funcIdArg;
        } elseif (!empty(\$funcionarioIdsParam)) {
            \$funcionarioIds = array_filter(array_map('intval', explode(',', \$funcionarioIdsParam)));
        }

        if (empty(\$funcionarioIds)) {
             return \$this->json(\$response, 400, ['erro' => true, 'mensagem' => 'Nenhum funcionário seleccionado.']);
        }

        \$resultados = [];
        foreach (\$funcionarioIds as \$fid) {
            \$dadosFuncionario = \$this->gerarDadosMarcacoesDiarias(\$db, \$fid, \$dataInicio, \$dataFim, \$perfil, \$user, \$tenantInfo);
            if (\$dadosFuncionario !== null) {
                \$resultados[] = \$dadosFuncionario;
            }
        }

        if (empty(\$resultados)) {
            return \$this->json(\$response, 404, ['erro' => true, 'mensagem' => 'Nenhum dado encontrado ou sem permissão.']);
        }

        if (\$formato === 'xlsx') {
            \$filename = count(\$resultados) > 1 ? "relatorio_marcacoes_multiplo" : "relatorio_marcacoes_{\$resultados[0]['funcionario']['numero']}";
            return \$this->exportarExcel(['relatorios' => \$resultados], 'marcacoes_diarias', \$filename, \$response);
        } elseif (\$formato === 'csv') {
            \$filename = count(\$resultados) > 1 ? "relatorio_marcacoes_multiplo" : "relatorio_marcacoes_{\$resultados[0]['funcionario']['numero']}";
            return \$this->exportarCSV(['relatorios' => \$resultados], 'marcacoes_diarias', \$filename, \$response);
        }

        // Maintain JSON format compatibility for single user
        if (count(\$resultados) === 1 && \$funcIdArg !== null) {
            return \$this->json(\$response, 200, \$resultados[0]);
        }

        return \$this->json(\$response, 200, \$resultados);
    }
EOT;

$code = str_replace($search3, $replace3, $code);

file_put_contents('app/Controllers/RelatorioController.php', $code);
