<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class PeriodoController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    /**
     * GET /api/periodos?ano=2026
     * Lista todos os períodos de um ano
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db     = $this->db();
        $params = $request->getQueryParams();
        $ano    = (int) ($params['ano'] ?? date('Y'));

        $stmt = $db->prepare("
            SELECT id, ano, mes, estado, data_inicio, data_fim, fechado_por, fechado_em, criado_em
            FROM periodos_mensais
            WHERE ano = :ano
            ORDER BY mes ASC
        ");
        $stmt->execute([':ano' => $ano]);
        $periodos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Complementar com meses que ainda não têm registo
        $config = $this->getConfigPeriodo($db);
        $diaInicio = (int) ($config['periodo_dia_inicio'] ?? 1);
        $diaFim    = (int) ($config['periodo_dia_fim'] ?? 31);

        $resultado = [];
        for ($mes = 1; $mes <= 12; $mes++) {
            $existente = null;
            foreach ($periodos as $p) {
                if ((int) $p['mes'] === $mes) {
                    $existente = $p;
                    break;
                }
            }
            if ($existente) {
                $resultado[] = $existente;
            } else {
                $resultado[] = [
                    'id'          => null,
                    'ano'         => $ano,
                    'mes'         => $mes,
                    'estado'      => 'aberto',
                    'data_inicio' => $this->calcularDataInicio($ano, $mes, $diaInicio),
                    'data_fim'    => $this->calcularDataFim($ano, $mes, $diaFim),
                    'fechado_por' => null,
                    'fechado_em'  => null,
                ];
            }
        }

        return $this->json(200, [
            'periodos'   => $resultado,
            'ano'        => $ano,
            'dia_inicio' => $diaInicio,
            'dia_fim'    => $diaFim,
        ]);
    }

    /**
     * POST /api/periodos/fechar
     * Fecha um período mensal
     */
    public function fechar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db   = $this->db();
        $body = $request->getParsedBody() ?? [];

        $ano = (int) ($body['ano'] ?? 0);
        $mes = (int) ($body['mes'] ?? 0);

        if (!$ano || !$mes || $mes < 1 || $mes > 12) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Ano e mês são obrigatórios.']);
        }

        // Verificar se já está fechado
        $check = $db->prepare("SELECT id, estado FROM periodos_mensais WHERE ano = :ano AND mes = :mes LIMIT 1");
        $check->execute([':ano' => $ano, ':mes' => $mes]);
        $existente = $check->fetch(PDO::FETCH_ASSOC);

        if ($existente && $existente['estado'] === 'fechado') {
            return $this->json(409, ['erro' => true, 'mensagem' => 'Este período já está fechado.']);
        }

        $config    = $this->getConfigPeriodo($db);
        $diaInicio = (int) ($config['periodo_dia_inicio'] ?? 1);
        $diaFim    = (int) ($config['periodo_dia_fim'] ?? 31);

        $dataInicio = $body['data_inicio'] ?? $this->calcularDataInicio($ano, $mes, $diaInicio);
        $dataFim    = $body['data_fim']    ?? $this->calcularDataFim($ano, $mes, $diaFim);

        if ($existente) {
            $db->prepare("
                UPDATE periodos_mensais
                SET estado = 'fechado', data_inicio = :di, data_fim = :df, fechado_em = NOW()
                WHERE ano = :ano AND mes = :mes
            ")->execute([':di' => $dataInicio, ':df' => $dataFim, ':ano' => $ano, ':mes' => $mes]);
        } else {
            $db->prepare("
                INSERT INTO periodos_mensais (ano, mes, estado, data_inicio, data_fim, fechado_em)
                VALUES (:ano, :mes, 'fechado', :di, :df, NOW())
            ")->execute([':ano' => $ano, ':mes' => $mes, ':di' => $dataInicio, ':df' => $dataFim]);
        }

        $meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                  'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

        return $this->json(200, [
            'mensagem' => "Período de {$meses[$mes]} {$ano} fechado com sucesso.",
        ]);
    }

    /**
     * POST /api/periodos/abrir
     * Reabre um período fechado (apenas super_admin_tenant)
     */
    public function abrir(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db   = $this->db();
        $body = $request->getParsedBody() ?? [];

        $ano = (int) ($body['ano'] ?? 0);
        $mes = (int) ($body['mes'] ?? 0);

        if (!$ano || !$mes) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Ano e mês são obrigatórios.']);
        }

        $db->prepare("
            UPDATE periodos_mensais SET estado = 'aberto', fechado_em = NULL
            WHERE ano = :ano AND mes = :mes
        ")->execute([':ano' => $ano, ':mes' => $mes]);

        $meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                  'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

        return $this->json(200, [
            'mensagem' => "Período de {$meses[$mes]} {$ano} reaberto.",
        ]);
    }

    /**
     * POST /api/periodos/configurar
     * Define o dia de início e fim do período mensal
     */
    public function configurar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $db   = $this->db();
        $body = $request->getParsedBody() ?? [];

        $diaInicio = (int) ($body['dia_inicio'] ?? 1);
        $diaFim    = (int) ($body['dia_fim'] ?? 31);

        if ($diaInicio < 1 || $diaInicio > 28) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Dia de início deve ser entre 1 e 28.']);
        }
        if ($diaFim < 1 || $diaFim > 31) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Dia de fim deve ser entre 1 e 31.']);
        }

        $this->upsertConfig($db, 'periodo_dia_inicio', (string) $diaInicio);
        $this->upsertConfig($db, 'periodo_dia_fim', (string) $diaFim);

        return $this->json(200, [
            'mensagem'   => 'Configuração do período actualizada.',
            'dia_inicio' => $diaInicio,
            'dia_fim'    => $diaFim,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getConfigPeriodo(PDO $db): array
    {
        $stmt = $db->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('periodo_dia_inicio','periodo_dia_fim')");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $config = [];
        foreach ($rows as $r) {
            $config[$r['chave']] = $r['valor'];
        }
        return $config;
    }

    private function upsertConfig(PDO $db, string $chave, string $valor): void
    {
        $db->prepare("
            INSERT INTO configuracoes (chave, valor) VALUES (:chave, :valor)
            ON DUPLICATE KEY UPDATE valor = :valor2
        ")->execute([':chave' => $chave, ':valor' => $valor, ':valor2' => $valor]);
    }

    private function calcularDataInicio(int $ano, int $mes, int $dia): string
    {
        // Se dia_inicio > 1, o período começa no mês anterior
        if ($dia > 1) {
            $mesAnterior = $mes === 1 ? 12 : $mes - 1;
            $anoAnterior = $mes === 1 ? $ano - 1 : $ano;
            $ultimoDia   = cal_days_in_month(CAL_GREGORIAN, $mesAnterior, $anoAnterior);
            $diaReal     = min($dia, $ultimoDia);
            return sprintf('%04d-%02d-%02d', $anoAnterior, $mesAnterior, $diaReal);
        }
        return sprintf('%04d-%02d-%02d', $ano, $mes, 1);
    }

    private function calcularDataFim(int $ano, int $mes, int $dia): string
    {
        $ultimoDia = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
        if ($dia >= $ultimoDia || $dia === 31) {
            return sprintf('%04d-%02d-%02d', $ano, $mes, $ultimoDia);
        }
        return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
