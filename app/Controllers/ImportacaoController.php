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
 * ImportacaoController — Importação de dados via CSV
 *
 * POST /api/importacao/funcionarios — Importa funcionários de CSV
 * POST /api/importacao/validar      — Valida CSV sem importar (dry-run)
 */
class ImportacaoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * POST /api/importacao/validar
     * Valida o CSV sem importar — devolve erros e pré-visualização
     */
    public function validar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $resultado = $this->processarCSV($request, dryRun: true);
        return $this->json(200, $resultado);
    }

    /**
     * POST /api/importacao/funcionarios
     * Importa funcionários do CSV
     */
    public function funcionarios(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $resultado = $this->processarCSV($request, dryRun: false);

        $status = empty($resultado['erros']) ? 200 : 422;
        return $this->json($status, $resultado);
    }

    private function processarCSV(ServerRequestInterface $request, bool $dryRun): array
    {	error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
        $uploadedFiles = $request->getUploadedFiles();
        $arquivo       = $uploadedFiles['ficheiro'] ?? null;

        if (!$arquivo || $arquivo->getError() !== UPLOAD_ERR_OK) {
            return ['erro' => true, 'mensagem' => 'Nenhum ficheiro enviado ou erro no upload.'];
        }

        $conteudo = $arquivo->getStream()->getContents();

        // Remover BOM UTF-8 se presente
        $conteudo = ltrim($conteudo, "\xEF\xBB\xBF");

        $linhas = array_filter(explode("\n", str_replace("\r\n", "\n", $conteudo)));
        $linhas = array_values($linhas);

        if (empty($linhas)) {
            return ['erro' => true, 'mensagem' => 'Ficheiro CSV vazio.'];
        }

        // Detectar separador (ponto e vírgula ou vírgula)
        $sep    = str_contains($linhas[0], ';') ? ';' : ',';
        $header = str_getcsv(array_shift($linhas), $sep);
        $header = array_map('trim', $header);

        // Colunas obrigatórias
        $obrigatorias = ['nome_completo', 'data_admissao'];
        $errosHeader  = [];
        foreach ($obrigatorias as $col) {
            if (!in_array($col, $header)) {
                $errosHeader[] = "Coluna obrigatória em falta: {$col}";
            }
        }
        if (!empty($errosHeader)) {
            return ['erro' => true, 'mensagem' => implode('; ', $errosHeader)];
        }

        $db              = $this->db();
        $erros           = [];
        $avisos          = [];
        $previa          = [];
        $importados      = 0;
        $ignorados       = 0;
        $numLinhaCSV     = 1;

        foreach ($linhas as $linha) {
            if (trim($linha) === '') continue;
            $numLinhaCSV++;

            $valores = str_getcsv($linha, $sep, '"', '\\');
            $row     = [];
            foreach ($header as $i => $col) {
                $row[$col] = isset($valores[$i]) ? trim($valores[$i]) : '';
            }

            // Validações por linha
            $errosLinha = [];

            if (empty($row['nome_completo'])) {
                $errosLinha[] = 'nome_completo é obrigatório';
            }

            if (empty($row['data_admissao'])) {
                $errosLinha[] = 'data_admissao é obrigatória';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['data_admissao'])) {
                $errosLinha[] = 'data_admissao deve estar no formato AAAA-MM-DD';
            }

            if (!empty($row['tipo_contrato']) && !in_array($row['tipo_contrato'], ['prazo_determinado','prazo_indeterminado','prestacao_servicos'])) {
                $errosLinha[] = 'tipo_contrato inválido (use: prazo_determinado, prazo_indeterminado, prestacao_servicos)';
            }

            if (!empty($row['genero']) && !in_array($row['genero'], ['M','F','Outro'])) {
                $errosLinha[] = 'genero inválido (use: M, F, Outro)';
            }

            if (!empty($row['vencimento_base_aoa']) && !is_numeric(str_replace(',','.',$row['vencimento_base_aoa']))) {
                $errosLinha[] = 'vencimento_base_aoa deve ser um número';
            }

            if (!empty($errosLinha)) {
                foreach ($errosLinha as $e) {
                    $erros[] = "Linha {$numLinhaCSV}: {$e}";
                }
                $ignorados++;
                continue;
            }

            // Verificar duplicado por NIF se fornecido
            if (!empty($row['nif'])) {
                $check = $db->prepare("SELECT id FROM funcionarios WHERE nif = :nif LIMIT 1");
                $check->execute([':nif' => $row['nif']]);
                if ($check->fetch()) {
                    $avisos[] = "Linha {$numLinhaCSV}: funcionário com NIF {$row['nif']} já existe — ignorado.";
                    $ignorados++;
                    continue;
                }
            }

            // Resolver departamento por nome — cria automaticamente se não existir
            $depId = null;
            if (!empty($row['departamento'])) {
                $dep = $db->prepare("SELECT id FROM departamentos WHERE nome = :nome LIMIT 1");
                $dep->execute([':nome' => $row['departamento']]);
                $depRow = $dep->fetch(PDO::FETCH_ASSOC);
                if ($depRow) {
                    $depId = $depRow['id'];
                } else {
                    // Criar departamento automaticamente
                    $db->prepare("INSERT INTO departamentos (nome, activo) VALUES (:nome, 1)")
                       ->execute([':nome' => $row['departamento']]);
                    $depId = (int) $db->lastInsertId();
                    $avisos[] = "Departamento '{$row['departamento']}' criado automaticamente.";
                }
            }

            // Usar número do CSV se fornecido, senão gerar automaticamente
            if (!empty($row['numero_funcionario'])) {
                // Verificar se o número já existe
                $checkNum = $db->prepare("SELECT id FROM funcionarios WHERE numero_funcionario = :num LIMIT 1");
                $checkNum->execute([':num' => $row['numero_funcionario']]);
                if ($checkNum->fetch()) {
                    $avisos[] = "Linha {$numLinhaCSV}: número '{$row['numero_funcionario']}' já existe — ignorado.";
                    $ignorados++;
                    continue;
                }
                $numero = $row['numero_funcionario'];
            } else {
                $ultimo = (int) $db->query("SELECT MAX(CAST(numero_funcionario AS UNSIGNED)) FROM funcionarios")->fetchColumn();
                $numero = str_pad((string)($ultimo + 1 + $importados), 4, '0', STR_PAD_LEFT);
            }

            $previa[] = [
                'linha'              => $numLinhaCSV,
                'numero'             => $numero,
                'nome_completo'      => $row['nome_completo'],
                'data_admissao'      => $row['data_admissao'],
                'departamento'       => $row['departamento'] ?? '',
                'vencimento_base_aoa' => $row['vencimento_base_aoa'] ?? '0',
            ];

            if (!$dryRun) {
                try {
                    $stmt = $db->prepare("
                        INSERT INTO funcionarios (
                            numero_funcionario, uuid, nome_completo, data_nascimento, genero,
                            nacionalidade, nif, niss, bi_numero, estado_civil, num_dependentes,
                            morada, municipio, provincia, telefone, email,
                            departamento_id, data_admissao, tipo_contrato, data_fim_contrato,
                            vencimento_base_aoa, estado
                        ) VALUES (
                            :numero, UUID(), :nome, :nasc, :genero,
                            :nac, :nif, :niss, :bi, :ecivil, :dep_num,
                            :morada, :municipio, :provincia, :tel, :email,
                            :dep_id, :admissao, :contrato, :fim_contrato,
                            :venc, 'activo'
                        )
                    ");
                    $stmt->execute([
                        ':numero'       => $numero,
                        ':nome'         => $row['nome_completo'],
                        ':nasc'         => !empty($row['data_nascimento']) ? $row['data_nascimento'] : null,
                        ':genero'       => !empty($row['genero']) ? $row['genero'] : null,
                        ':nac'          => $row['nacionalidade'] ?? 'Angolana',
                        ':nif'          => $row['nif'] ?? null,
                        ':niss'         => $row['niss'] ?? null,
                        ':bi'           => $row['bi_numero'] ?? null,
                        ':ecivil'       => !empty($row['estado_civil']) ? $row['estado_civil'] : null,
                        ':dep_num'      => (int)($row['num_dependentes'] ?? 0),
                        ':morada'       => $row['morada'] ?? null,
                        ':municipio'    => $row['municipio'] ?? null,
                        ':provincia'    => $row['provincia'] ?? null,
                        ':tel'          => $row['telefone'] ?? null,
                        ':email'        => !empty($row['email']) ? $row['email'] : null,
                        ':dep_id'       => $depId,
                        ':admissao'     => $row['data_admissao'],
                        ':contrato'     => $row['tipo_contrato'] ?? 'prazo_indeterminado',
                        ':fim_contrato' => !empty($row['data_fim_contrato']) ? $row['data_fim_contrato'] : null,
                        ':venc'         => (float)str_replace(',', '.', $row['vencimento_base_aoa'] ?? '0'),
                    ]);

                    // Saldo de férias
                    $fid = (int)$db->lastInsertId();
                    $db->prepare("INSERT IGNORE INTO ferias (funcionario_id, ano, dias_direito, dias_gozados, dias_pendentes) VALUES (:fid, :ano, 22, 0, 22)")
                       ->execute([':fid' => $fid, ':ano' => date('Y')]);
                } catch (\PDOException $e) {
                    $avisos[] = "Linha {$numLinhaCSV}: erro ao inserir '{$row['nome_completo']}' — " . $e->getMessage();
                    $ignorados++;
                    $importados--;
                    continue;
                }
            }

            $importados++;
        }

        return [
            'dry_run'    => $dryRun,
            'total'      => count($linhas),
            'importados' => $importados,
            'ignorados'  => $ignorados,
            'erros'      => $erros,
            'avisos'     => $avisos,
            'previa'     => $dryRun ? $previa : [],
            'mensagem'   => $dryRun
                ? "Validação concluída: {$importados} registos válidos, {$ignorados} com erros."
                : "Importação concluída: {$importados} funcionários importados, {$ignorados} ignorados.",
        ];
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
