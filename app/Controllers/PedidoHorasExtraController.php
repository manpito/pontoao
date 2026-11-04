<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use App\Services\EscalaService;
use App\Services\FeriadoService;
use App\Services\LimitesHorasExtra;
use App\Services\PermissaoFuncionario;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Pedidos de horas extra — Lei n.º 12/23 (LGT), arts. 184.º a 187.º
 * Circuito: supervisor (da equipa) ou RH criam; o gestor de RH (rh_manager) aprova;
 * pedidos de supervisores só o Administrador aprova; o Administrador não tem restrições.
 */
class PedidoHorasExtraController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    private function auditar(PDO $db, ServerRequestInterface $request, string $accao, int $entidadeId, ?array $antes, ?array $depois): void
    {
        $user = $request->getAttribute('auth_user');
        $db->prepare("
            INSERT INTO log_auditoria (utilizador_id, accao, entidade, entidade_id, dados_antes, dados_depois, ip)
            VALUES (:uid, :accao, 'pedido_horas_extra', :eid, :antes, :depois, :ip)
        ")->execute([
            ':uid'    => $user ? (int) $user->sub : null,
            ':accao'  => $accao,
            ':eid'    => $entidadeId,
            ':antes'  => $antes ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
            ':depois' => $depois ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    private function funcionarioESupervisor(PDO $db, int $funcionarioId): bool
    {
        $st = $db->prepare("SELECT id FROM utilizadores WHERE funcionario_id = :fid AND perfil = 'supervisor' AND activo = 1 LIMIT 1");
        $st->execute([':fid' => $funcionarioId]);
        return (bool) $st->fetch();
    }

    /**
     * GET /api/pedidos-horas-extra
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $user = $request->getAttribute('auth_user');
        $db = $this->db();

        $where = [];
        $bind = [];

        if ($user->perfil === 'funcionario') {
            $where[] = 'phe.funcionario_id = :fid';
            $bind[':fid'] = (int) $user->funcionario_id;
        } elseif ($user->perfil === 'supervisor') {
            $where[] = '(f.supervisor_id = :sid OR f.id = :sid_self)';
            $bind[':sid'] = (int) ($user->funcionario_id ?? 0);
            $bind[':sid_self'] = (int) ($user->funcionario_id ?? 0);
            if (!empty($params['funcionario_id'])) {
                $where[] = 'phe.funcionario_id = :fid';
                $bind[':fid'] = (int) $params['funcionario_id'];
            }
        } elseif (!empty($params['funcionario_id'])) {
            $where[] = 'phe.funcionario_id = :fid';
            $bind[':fid'] = (int) $params['funcionario_id'];
        }

        if (!empty($params['estado'])) {
            $where[] = 'phe.estado = :estado';
            $bind[':estado'] = $params['estado'];
        }
        if (!empty($params['data_inicio'])) {
            $where[] = 'phe.data >= :data_inicio';
            $bind[':data_inicio'] = $params['data_inicio'];
        }
        if (!empty($params['data_fim'])) {
            $where[] = 'phe.data <= :data_fim';
            $bind[':data_fim'] = $params['data_fim'];
        }

        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $db->prepare("
            SELECT phe.*, f.nome_completo, f.numero_funcionario
            FROM pedidos_horas_extra phe
            JOIN funcionarios f ON phe.funcionario_id = f.id
            $whereStr
            ORDER BY phe.data DESC, phe.id DESC
        ");
        $stmt->execute($bind);

        return $this->json(200, ['dados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * POST /api/pedidos-horas-extra
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];
        $user = $request->getAttribute('auth_user');
        $db = $this->db();
        $perfil = (string) ($user->perfil ?? '');

        if (!in_array($perfil, ['super_admin_tenant', 'rh_manager', 'rh_colaborador', 'supervisor'], true)) {
            return $this->json(403, ['erro' => true, 'mensagem' => 'Sem permissão para submeter pedidos de horas extra.']);
        }

        $funcionarioId = (int) ($body['funcionario_id'] ?? 0);
        if ($funcionarioId <= 0) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'O funcionário é obrigatório.']);
        }

        $erroPermissao = PermissaoFuncionario::verificar($db, $user, $funcionarioId);
        if ($erroPermissao !== null) {
            return $this->json(403, ['erro' => true, 'mensagem' => $erroPermissao]);
        }

        $stFunc = $db->prepare("SELECT id FROM funcionarios WHERE id = :id AND estado = 'activo' LIMIT 1");
        $stFunc->execute([':id' => $funcionarioId]);
        if (!$stFunc->fetch()) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Funcionário não encontrado ou inactivo.']);
        }

        $data = (string) ($body['data'] ?? '');
        $dtData = \DateTime::createFromFormat('Y-m-d', $data);
        if (!$dtData || $dtData->format('Y-m-d') !== $data) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Data inválida.']);
        }

        $fundamento = (string) ($body['fundamento'] ?? '');
        if (!in_array($fundamento, LimitesHorasExtra::FUNDAMENTOS, true)) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Indique o fundamento legal (alíneas a) a g) do n.º 2 do art. 184.º).']);
        }

        $horaInicio = (string) ($body['hora_inicio'] ?? '');
        $horaFim    = (string) ($body['hora_fim'] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaInicio) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaFim)) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Indique a hora prevista de início e de fim (HH:MM).']);
        }
        if ($horaInicio === $horaFim) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'A hora de fim tem de ser diferente da hora de início.']);
        }
        $minutos = LimitesHorasExtra::minutosEntre($horaInicio, $horaFim);

        $motivo = trim((string) ($body['motivo'] ?? ''));
        if (mb_strlen($motivo) < 10) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'O motivo é obrigatório e deve ter pelo menos 10 caracteres.']);
        }

        // Pedido prévio (art. 184.º, n.º 3): só hoje ou datas futuras, salvo fundamentos a) e d) ou Administrador
        $hoje = (new \DateTime('now', new \DateTimeZone('Africa/Luanda')))->format('Y-m-d');
        if ($data < $hoje && $perfil !== 'super_admin_tenant' && !in_array($fundamento, LimitesHorasExtra::SEM_LIMITE, true)) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'O trabalho extraordinário tem de ser determinado previamente (art. 184.º, n.º 3): só é possível pedir para hoje ou datas futuras. Para datas passadas, só nos fundamentos a) e d).']);
        }

        // Período fechado
        $stPer = $db->prepare("SELECT id FROM periodos_mensais WHERE estado = 'fechado' AND :data BETWEEN data_inicio AND data_fim LIMIT 1");
        $stPer->execute([':data' => $data]);
        if ($stPer->fetch()) {
            return $this->json(409, ['erro' => true, 'mensagem' => 'O período mensal desta data está fechado.']);
        }

        // Um pedido por funcionário e por dia
        $check = $db->prepare("SELECT id FROM pedidos_horas_extra WHERE funcionario_id = :fid AND data = :data AND estado <> 'rejeitado' LIMIT 1");
        $check->execute([':fid' => $funcionarioId, ':data' => $data]);
        if ($check->fetch()) {
            return $this->json(409, ['erro' => true, 'mensagem' => 'Já existe um pedido para este funcionário nesta data.']);
        }

        // Dia normal de trabalho: não é feriado e o turno não é folga (sem escala: segunda a sexta)
        $feriado = (new FeriadoService($db))->isFeriado($data);
        $turno = (new EscalaService($db))->calcularTurnoEm($funcionarioId, $data);
        if ($turno) {
            $diaNormal = !$feriado && !in_array($turno['tipo'] ?? '', ['folga', 'compensatorio'], true);
        } else {
            $diaNormal = !$feriado && (int) $dtData->format('N') <= 5;
        }

        $inicioMes = $dtData->format('Y-m-01');
        $fimMes    = $dtData->format('Y-m-t');
        $stMes = $db->prepare("SELECT COALESCE(SUM(minutos), 0) FROM pedidos_horas_extra WHERE funcionario_id = :fid AND estado <> 'rejeitado' AND data BETWEEN :ini AND :fim");
        $stMes->execute([':fid' => $funcionarioId, ':ini' => $inicioMes, ':fim' => $fimMes]);
        $minutosMes = (int) $stMes->fetchColumn();

        $inicioAno = $dtData->format('Y-01-01');
        $fimAno    = $dtData->format('Y-12-31');
        $stAno = $db->prepare("SELECT COALESCE(SUM(minutos), 0) FROM pedidos_horas_extra WHERE funcionario_id = :fid AND estado <> 'rejeitado' AND data BETWEEN :ini AND :fim");
        $stAno->execute([':fid' => $funcionarioId, ':ini' => $inicioAno, ':fim' => $fimAno]);
        $minutosAno = (int) $stAno->fetchColumn();

        $igtRef = trim((string) ($body['igt_autorizacao_ref'] ?? ''));
        $consentimento = !empty($body['consentimento_trabalhador']) ? 1 : 0;

        $erroLimite = LimitesHorasExtra::verificar($fundamento, $minutos, $diaNormal, $minutosMes, $minutosAno);
        if ($erroLimite !== null && ($perfil !== 'super_admin_tenant' || $igtRef === '' || $consentimento !== 1)) {
            return $this->json(422, ['erro' => true, 'mensagem' => $erroLimite . ' Acima dos limites, só o Administrador pode registar o pedido, com a referência da autorização da Inspecção Geral do Trabalho e o consentimento do trabalhador (art. 185.º, n.º 3).']);
        }

        $stmt = $db->prepare("
            INSERT INTO pedidos_horas_extra
                (funcionario_id, data, minutos, tipo, motivo, fundamento, hora_inicio, hora_fim,
                 igt_autorizacao_ref, consentimento_trabalhador, estado, submetido_por)
            VALUES
                (:fid, :data, :minutos, 'normal', :motivo, :fundamento, :hini, :hfim,
                 :igt, :consent, 'pendente', :submetido_por)
        ");
        $stmt->execute([
            ':fid'           => $funcionarioId,
            ':data'          => $data,
            ':minutos'       => $minutos,
            ':motivo'        => $motivo,
            ':fundamento'    => $fundamento,
            ':hini'          => $horaInicio . ':00',
            ':hfim'          => $horaFim . ':00',
            ':igt'           => $igtRef !== '' ? $igtRef : null,
            ':consent'       => $consentimento,
            ':submetido_por' => (int) $user->sub,
        ]);
        $id = (int) $db->lastInsertId();

        $this->auditar($db, $request, 'horas_extra.pedido_criado', $id, null, [
            'funcionario_id' => $funcionarioId, 'data' => $data, 'minutos' => $minutos,
            'fundamento' => $fundamento, 'hora_inicio' => $horaInicio, 'hora_fim' => $horaFim,
            'acima_limites' => $erroLimite !== null, 'igt_autorizacao_ref' => $igtRef !== '' ? $igtRef : null,
        ]);

        return $this->json(201, ['mensagem' => 'Pedido submetido com sucesso.', 'id' => $id]);
    }

    /**
     * PUT /api/pedidos-horas-extra/{id}/aprovar
     */
    public function aprovar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $user = $request->getAttribute('auth_user');
        $db = $this->db();
        $perfil = (string) ($user->perfil ?? '');

        $stmt = $db->prepare("SELECT * FROM pedidos_horas_extra WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pedido) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Pedido não encontrado.']);
        }

        $erroPermissao = PermissaoFuncionario::verificar($db, $user, (int) $pedido['funcionario_id']);
        if ($erroPermissao !== null) {
            return $this->json(403, ['erro' => true, 'mensagem' => $erroPermissao]);
        }

        if (!in_array($pedido['estado'], ['pendente', 'aprovado_rh'], true)) {
            return $this->json(409, ['erro' => true, 'mensagem' => 'Só é possível aprovar pedidos pendentes.']);
        }

        if ($perfil === 'rh_manager') {
            if ($this->funcionarioESupervisor($db, (int) $pedido['funcionario_id'])) {
                return $this->json(403, ['erro' => true, 'mensagem' => 'Os pedidos de horas extra de supervisores só podem ser aprovados pelo Administrador.']);
            }
        } elseif ($perfil !== 'super_admin_tenant') {
            return $this->json(403, ['erro' => true, 'mensagem' => 'Só o gestor de RH ou o Administrador podem aprovar pedidos de horas extra.']);
        }

        $db->prepare("UPDATE pedidos_horas_extra SET estado = 'aprovado', aprovado_por = :uid, data_aprovacao = NOW() WHERE id = :id")
           ->execute([':uid' => (int) $user->sub, ':id' => $id]);

        $this->auditar($db, $request, 'horas_extra.pedido_aprovado', $id, ['estado' => $pedido['estado']], ['estado' => 'aprovado']);

        return $this->json(200, ['mensagem' => 'Pedido aprovado.']);
    }

    /**
     * PUT /api/pedidos-horas-extra/{id}/rejeitar
     */
    public function rejeitar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $user = $request->getAttribute('auth_user');
        $db = $this->db();
        $perfil = (string) ($user->perfil ?? '');

        if (!in_array($perfil, ['rh_manager', 'super_admin_tenant'], true)) {
            return $this->json(403, ['erro' => true, 'mensagem' => 'Só o gestor de RH ou o Administrador podem rejeitar pedidos de horas extra.']);
        }

        $stmt = $db->prepare("SELECT * FROM pedidos_horas_extra WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pedido) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Pedido não encontrado.']);
        }

        $erroPermissao = PermissaoFuncionario::verificar($db, $user, (int) $pedido['funcionario_id']);
        if ($erroPermissao !== null) {
            return $this->json(403, ['erro' => true, 'mensagem' => $erroPermissao]);
        }

        if ($perfil === 'rh_manager' && $this->funcionarioESupervisor($db, (int) $pedido['funcionario_id'])) {
            return $this->json(403, ['erro' => true, 'mensagem' => 'Os pedidos de horas extra de supervisores só podem ser decididos pelo Administrador.']);
        }

        if (!in_array($pedido['estado'], ['pendente', 'aprovado_rh'], true)) {
            return $this->json(409, ['erro' => true, 'mensagem' => 'Só é possível rejeitar pedidos pendentes.']);
        }

        $motivo = trim((string) ($body['motivo'] ?? ($body['motivo_rejeicao'] ?? '')));
        if ($motivo === '') {
            return $this->json(422, ['erro' => true, 'mensagem' => 'O motivo da rejeição é obrigatório.']);
        }

        $db->prepare("UPDATE pedidos_horas_extra SET estado = 'rejeitado', rejeitado_motivo = :motivo WHERE id = :id")
           ->execute([':motivo' => $motivo, ':id' => $id]);

        $this->auditar($db, $request, 'horas_extra.pedido_rejeitado', $id, ['estado' => $pedido['estado']], ['estado' => 'rejeitado', 'motivo' => $motivo]);

        return $this->json(200, ['mensagem' => 'Pedido rejeitado.']);
    }

    /**
     * PUT /api/pedidos-horas-extra/{id}/registar
     * Registo do trabalho extraordinário prestado (Lei n.º 12/23, art. 187.º) — só gestor de RH e Administrador.
     */
    public function registar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $user = $request->getAttribute('auth_user');
        $db = $this->db();
        $perfil = (string) ($user->perfil ?? '');

        if (!in_array($perfil, ['rh_manager', 'super_admin_tenant'], true)) {
            return $this->json(403, ['erro' => true, 'mensagem' => 'Só o gestor de RH ou o Administrador podem registar as horas realizadas.']);
        }

        $stmt = $db->prepare("SELECT * FROM pedidos_horas_extra WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pedido) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Pedido não encontrado.']);
        }

        $erroPermissao = PermissaoFuncionario::verificar($db, $user, (int) $pedido['funcionario_id']);
        if ($erroPermissao !== null) {
            return $this->json(403, ['erro' => true, 'mensagem' => $erroPermissao]);
        }

        if ($pedido['estado'] !== 'aprovado') {
            return $this->json(409, ['erro' => true, 'mensagem' => 'Só é possível registar as horas realizadas de pedidos aprovados.']);
        }

        $hoje = (new \DateTime('now', new \DateTimeZone('Africa/Luanda')))->format('Y-m-d');
        if ($pedido['data'] > $hoje) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Só é possível registar as horas realizadas depois da data do trabalho.']);
        }

        $stPer = $db->prepare("SELECT id FROM periodos_mensais WHERE estado = 'fechado' AND :data BETWEEN data_inicio AND data_fim LIMIT 1");
        $stPer->execute([':data' => $pedido['data']]);
        if ($stPer->fetch()) {
            return $this->json(409, ['erro' => true, 'mensagem' => 'O período mensal desta data está fechado.']);
        }

        $horaInicio = (string) ($body['hora_inicio_real'] ?? '');
        $horaFim    = (string) ($body['hora_fim_real'] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaInicio) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaFim)) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Indique a hora real de início e de fim (HH:MM).']);
        }
        if ($horaInicio === $horaFim) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'A hora de fim tem de ser diferente da hora de início.']);
        }

        $justificacao = trim((string) ($body['justificacao'] ?? ''));
        if (mb_strlen($justificacao) < 10) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'A justificação é obrigatória e deve ter pelo menos 10 caracteres.']);
        }

        $minutosReais = LimitesHorasExtra::minutosEntre($horaInicio, $horaFim);
        $inicioReal = $pedido['data'] . ' ' . $horaInicio . ':00';
        $dataFim = $pedido['data'];
        if ($horaFim <= $horaInicio) {
            $dataFim = (new \DateTime($pedido['data']))->modify('+1 day')->format('Y-m-d');
        }
        $fimReal = $dataFim . ' ' . $horaFim . ':00';

        $db->prepare("
            UPDATE pedidos_horas_extra
            SET inicio_real = :ini, fim_real = :fim, minutos_reais = :min, registo_justificacao = :just,
                registado_por = :uid, registado_em = NOW(), visto_em = NULL, visto_por = NULL
            WHERE id = :id
        ")->execute([
            ':ini' => $inicioReal, ':fim' => $fimReal, ':min' => $minutosReais,
            ':just' => $justificacao, ':uid' => (int) $user->sub, ':id' => $id,
        ]);

        $this->auditar($db, $request, 'horas_extra.registo_realizado', $id,
            ['inicio_real' => $pedido['inicio_real'] ?? null, 'fim_real' => $pedido['fim_real'] ?? null, 'minutos_reais' => $pedido['minutos_reais'] ?? null, 'visto_em' => $pedido['visto_em'] ?? null],
            ['inicio_real' => $inicioReal, 'fim_real' => $fimReal, 'minutos_reais' => $minutosReais, 'justificacao' => $justificacao]
        );

        return $this->json(200, ['mensagem' => 'Horas realizadas registadas. Aguarda o visto do trabalhador.']);
    }

    /**
     * PUT /api/pedidos-horas-extra/{id}/visar
     * Visto do trabalhador no registo de trabalho extraordinário (Lei n.º 12/23, art. 187.º, n.º 2) — só o próprio.
     */
    public function visar(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $user = $request->getAttribute('auth_user');
        $db = $this->db();

        $stmt = $db->prepare("SELECT * FROM pedidos_horas_extra WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pedido) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Registo não encontrado.']);
        }

        if ((int) $pedido['funcionario_id'] !== (int) ($user->funcionario_id ?? 0)) {
            return $this->json(403, ['erro' => true, 'mensagem' => 'Só o próprio trabalhador pode visar este registo.']);
        }

        if ($pedido['inicio_real'] === null) {
            return $this->json(409, ['erro' => true, 'mensagem' => 'Este registo ainda não tem as horas realizadas.']);
        }

        if ($pedido['visto_em'] !== null) {
            return $this->json(409, ['erro' => true, 'mensagem' => 'Este registo já foi visado.']);
        }

        $db->prepare("UPDATE pedidos_horas_extra SET visto_em = NOW(), visto_por = :uid WHERE id = :id")
           ->execute([':uid' => (int) $user->sub, ':id' => $id]);

        $this->auditar($db, $request, 'horas_extra.registo_visado', $id, null, ['visto_por' => (int) $user->sub]);

        return $this->json(200, ['mensagem' => 'Registo visado.']);
    }

    /**
     * GET /api/pedidos-horas-extra/pendentes
     */
    public function pendentes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('auth_user');
        $db = $this->db();

        $where = ["phe.estado IN ('pendente', 'aprovado_rh')"];
        $bind = [];

        if ($user->perfil === 'funcionario') {
            $where[] = 'phe.funcionario_id = :fid';
            $bind[':fid'] = (int) $user->funcionario_id;
        } elseif ($user->perfil === 'supervisor') {
            $where[] = '(f.supervisor_id = :sid OR f.id = :sid_self)';
            $bind[':sid'] = (int) $user->funcionario_id;
            $bind[':sid_self'] = (int) $user->funcionario_id;
        }

        $whereStr = 'WHERE ' . implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM pedidos_horas_extra phe
            JOIN funcionarios f ON phe.funcionario_id = f.id
            $whereStr
        ");
        $stmt->execute($bind);

        return $this->json(200, ['total' => (int) $stmt->fetchColumn()]);
    }
}
