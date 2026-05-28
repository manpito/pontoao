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
 * FuncionarioController — CRUD de funcionários do tenant
 * Conforme LGT Lei 7/15 (ficha completa, NISS, NIF, tipo contrato)
 */
class FuncionarioController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve();
        if (!$sub) {
            // Ambiente local de testes — usar primeira DB tenant disponível via header
            $sub = $_SERVER['HTTP_X_TENANT'] ?? null;
        }
        return Database::tenant($sub);
    }

    /**
     * GET /api/funcionarios
     * Suporta filtros: ?estado=activo&departamento_id=1&search=nome
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $user   = $request->getAttribute('auth_user');
        $perfil = $request->getAttribute('auth_perfil');

        $where  = ['f.estado != "desligado"'];
        $bind   = [];

        // Filtro supervisor: apenas a sua equipa
        if ($perfil === 'supervisor' && !empty($user->funcionario_id)) {
            $where[] = '(f.supervisor_id = :sid OR f.id = :sid_self)';
            $bind[':sid'] = (int) $user->funcionario_id;
            $bind[':sid_self'] = (int) $user->funcionario_id;
        }

        if (!empty($params['estado'])) {
            $where[] = 'f.estado = :estado';
            $bind[':estado'] = $params['estado'];
        }

        if (!empty($params['departamento_id'])) {
            $where[] = 'f.departamento_id = :dep_id';
            $bind[':dep_id'] = (int) $params['departamento_id'];
        }

        if (!empty($params['search'])) {
            $where[] = '(f.nome_completo LIKE :search OR f.numero_funcionario LIKE :search OR f.email LIKE :search)';
            $bind[':search'] = '%' . $params['search'] . '%';
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->db()->prepare("
            SELECT
                f.id, f.uuid, f.numero_funcionario, f.nome_completo, f.email,
                f.telefone, f.data_admissao, f.tipo_contrato, f.data_fim_contrato,
                f.vencimento_base_aoa, f.estado, f.nif, f.niss, f.num_dependentes,
                f.genero, f.data_nascimento, f.foto_url,
                d.nome AS departamento, c.nome AS cargo
            FROM funcionarios f
            LEFT JOIN departamentos d ON f.departamento_id = d.id
            LEFT JOIN cargos c ON f.cargo_id = c.id
            WHERE {$whereStr}
            ORDER BY f.nome_completo ASC
        ");
        $stmt->execute($bind);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Omitir vencimento para rh_colaborador e supervisor
        if ($perfil === 'rh_colaborador' || $perfil === 'supervisor') {
            $dados = array_map(function($f) {
                $f['vencimento_base_aoa'] = 0;
                return $f;
            }, $dados);
        }

        return $this->json(200, [
            'dados' => $dados,
            'total' => count($dados),
        ]);
    }

    /**
     * GET /api/funcionarios/{id}
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id     = (int) $args['id'];
        $perfil = $request->getAttribute('auth_perfil');
        $stmt = $this->db()->prepare("
            SELECT
                f.*,
                d.nome AS departamento_nome,
                c.nome AS cargo_nome,
                h.nome AS horario_nome
            FROM funcionarios f
            LEFT JOIN departamentos d ON f.departamento_id = d.id
            LEFT JOIN cargos c ON f.cargo_id = c.id
            LEFT JOIN funcionario_horario fh ON fh.funcionario_id = f.id AND fh.data_fim IS NULL
            LEFT JOIN horarios h ON fh.horario_id = h.id
            WHERE f.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $funcionario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$funcionario) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Funcionário não encontrado.']);
        }

        // Omitir vencimento para rh_colaborador e supervisor
        if ($perfil === 'rh_colaborador' || $perfil === 'supervisor') {
            $funcionario['vencimento_base_aoa'] = 0;
        }

        return $this->json(200, ['dados' => $funcionario]);
    }

    /**
     * POST /api/funcionarios
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];

        $erro = $this->validar($body);
        if ($erro) {
            return $this->json(422, ['erro' => true, 'mensagem' => $erro]);
        }

        $db = $this->db();

        // Gerar número de funcionário sequencial
        $ultimo = $db->query("SELECT MAX(CAST(numero_funcionario AS UNSIGNED)) FROM funcionarios")->fetchColumn();
        $numero = str_pad((int) $ultimo + 1, 4, '0', STR_PAD_LEFT);
        $uuid   = $this->uuid();

        $stmt = $db->prepare("
            INSERT INTO funcionarios (
                numero_funcionario, uuid, nome_completo, data_nascimento, genero,
                nacionalidade, nif, niss, bi_numero, bi_validade,
                estado_civil, num_dependentes, morada, municipio, provincia,
                telefone, telefone_alternativo, email,
                departamento_id, cargo_id, supervisor_id,
                data_admissao, tipo_contrato, data_fim_contrato,
                vencimento_base_aoa, centro_custo, estado
            ) VALUES (
                :numero, :uuid, :nome_completo, :data_nascimento, :genero,
                :nacionalidade, :nif, :niss, :bi_numero, :bi_validade,
                :estado_civil, :num_dependentes, :morada, :municipio, :provincia,
                :telefone, :telefone_alternativo, :email,
                :departamento_id, :cargo_id, :supervisor_id,
                :data_admissao, :tipo_contrato, :data_fim_contrato,
                :vencimento_base_aoa, :centro_custo, 'activo'
            )
        ");

        $stmt->execute([
            ':numero'               => $numero,
            ':uuid'                 => $uuid,
            ':nome_completo'        => $body['nome_completo'],
            ':data_nascimento'      => $body['data_nascimento'] ?? null,
            ':genero'               => $body['genero'] ?? null,
            ':nacionalidade'        => $body['nacionalidade'] ?? 'Angolana',
            ':nif'                  => $body['nif'] ?? null,
            ':niss'                 => $body['niss'] ?? null,
            ':bi_numero'            => $body['bi_numero'] ?? null,
            ':bi_validade'          => $body['bi_validade'] ?? null,
            ':estado_civil'         => $body['estado_civil'] ?? null,
            ':num_dependentes'      => (int) ($body['num_dependentes'] ?? 0),
            ':morada'               => $body['morada'] ?? null,
            ':municipio'            => $body['municipio'] ?? null,
            ':provincia'            => $body['provincia'] ?? null,
            ':telefone'             => $body['telefone'] ?? null,
            ':telefone_alternativo' => $body['telefone_alternativo'] ?? null,
            ':email'                => $body['email'] ?? null,
            ':departamento_id'      => $body['departamento_id'] ? (int) $body['departamento_id'] : null,
            ':cargo_id'             => $body['cargo_id'] ? (int) $body['cargo_id'] : null,
            ':supervisor_id'        => $body['supervisor_id'] ? (int) $body['supervisor_id'] : null,
            ':data_admissao'        => $body['data_admissao'],
            ':tipo_contrato'        => $body['tipo_contrato'] ?? 'prazo_indeterminado',
            ':data_fim_contrato'    => $body['data_fim_contrato'] ?? null,
            ':vencimento_base_aoa'  => (float) ($body['vencimento_base_aoa'] ?? 0),
            ':centro_custo'         => $body['centro_custo'] ?? null,
        ]);

        $id = (int) $db->lastInsertId();

        // Associar horário se fornecido
        if (!empty($body['horario_id'])) {
            $db->prepare("
                INSERT INTO funcionario_horario (funcionario_id, horario_id, data_inicio)
                VALUES (:fid, :hid, :data)
            ")->execute([
                ':fid'  => $id,
                ':hid'  => (int) $body['horario_id'],
                ':data' => $body['data_admissao'],
            ]);
        }

        // Criar saldo de férias do ano corrente (22 dias LGT Art.º 215.º)
        $db->prepare("
            INSERT IGNORE INTO ferias (funcionario_id, ano, dias_direito, dias_gozados, dias_pendentes)
            VALUES (:fid, :ano, 22, 0, 22)
        ")->execute([':fid' => $id, ':ano' => date('Y')]);

        $this->auditoria($db, $request, 'funcionario.criado', 'funcionario', $id, null, ['numero' => $numero, 'nome' => $body['nome_completo']]);

        return $this->json(201, [
            'mensagem'           => 'Funcionário criado com sucesso.',
            'id'                 => $id,
            'numero_funcionario' => $numero,
        ]);
    }

    /**
     * PUT /api/funcionarios/{id}
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $db   = $this->db();

        $stmt = $db->prepare("SELECT * FROM funcionarios WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $antes = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$antes) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Funcionário não encontrado.']);
        }

        $campos = [
            'nome_completo', 'data_nascimento', 'genero', 'nacionalidade',
            'nif', 'niss', 'bi_numero', 'bi_validade', 'estado_civil', 'num_dependentes',
            'morada', 'municipio', 'provincia', 'telefone', 'telefone_alternativo', 'email',
            'departamento_id', 'cargo_id', 'supervisor_id', 'tipo_contrato',
            'data_fim_contrato', 'vencimento_base_aoa', 'centro_custo', 'estado',
        ];

        $sets  = [];
        $bind  = [':id' => $id];

        foreach ($campos as $campo) {
            if (array_key_exists($campo, $body)) {
                $sets[]         = "`{$campo}` = :{$campo}";
                $bind[":{$campo}"] = $body[$campo];
            }
        }

        if (empty($sets)) {
            return $this->json(400, ['erro' => true, 'mensagem' => 'Nenhum campo para actualizar.']);
        }

        $db->prepare("UPDATE funcionarios SET " . implode(', ', $sets) . " WHERE id = :id")->execute($bind);

        // Actualizar horário se fornecido
        if (!empty($body['horario_id'])) {
            $db->prepare("UPDATE funcionario_horario SET data_fim = CURDATE() WHERE funcionario_id = :fid AND data_fim IS NULL")
               ->execute([':fid' => $id]);
            $db->prepare("INSERT INTO funcionario_horario (funcionario_id, horario_id, data_inicio) VALUES (:fid, :hid, CURDATE())")
               ->execute([':fid' => $id, ':hid' => (int) $body['horario_id']]);
        }

        $this->auditoria($db, $request, 'funcionario.actualizado', 'funcionario', $id, $antes, $body);

        return $this->json(200, ['mensagem' => 'Funcionário actualizado com sucesso.']);
    }

    /**
     * DELETE /api/funcionarios/{id}
     * Não elimina — desliga o funcionário (LGT exige histórico)
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $db   = $this->db();

        $stmt = $db->prepare("SELECT id, nome_completo FROM funcionarios WHERE id = :id AND estado != 'desligado' LIMIT 1");
        $stmt->execute([':id' => $id]);
        $func = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$func) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Funcionário não encontrado ou já desligado.']);
        }

        $db->prepare("
            UPDATE funcionarios
            SET estado = 'desligado', data_desligamento = CURDATE(), motivo_desligamento = :motivo
            WHERE id = :id
        ")->execute([
            ':motivo' => $body['motivo'] ?? 'Desligamento registado pelo sistema.',
            ':id'     => $id,
        ]);

        // Fechar horário activo
        $db->prepare("UPDATE funcionario_horario SET data_fim = CURDATE() WHERE funcionario_id = :fid AND data_fim IS NULL")
           ->execute([':fid' => $id]);

        $this->auditoria($db, $request, 'funcionario.desligado', 'funcionario', $id, null, ['motivo' => $body['motivo'] ?? null]);

        return $this->json(200, ['mensagem' => "Funcionário '{$func['nome_completo']}' desligado com sucesso."]);
    }

    private function validar(array $body): ?string
    {
        if (empty($body['nome_completo'])) {
            return 'O campo nome_completo é obrigatório.';
        }
        if (empty($body['data_admissao'])) {
            return 'O campo data_admissao é obrigatório.';
        }
        if (!empty($body['tipo_contrato']) && !in_array($body['tipo_contrato'], ['prazo_determinado', 'prazo_indeterminado', 'prestacao_servicos'])) {
            return 'tipo_contrato inválido.';
        }
        if ($body['tipo_contrato'] === 'prazo_determinado' && empty($body['data_fim_contrato'])) {
            return 'data_fim_contrato é obrigatória para contratos a prazo determinado.';
        }
        return null;
    }

    private function auditoria(PDO $db, ServerRequestInterface $request, string $accao, string $entidade, int $entidadeId, ?array $antes, ?array $depois): void
    {
        $user = $request->getAttribute('auth_user');
        $db->prepare("
            INSERT INTO log_auditoria (utilizador_id, accao, entidade, entidade_id, dados_antes, dados_depois, ip, user_agent)
            VALUES (:uid, :accao, :entidade, :eid, :antes, :depois, :ip, :ua)
        ")->execute([
            ':uid'      => $user ? (int) $user->sub : null,
            ':accao'    => $accao,
            ':entidade' => $entidade,
            ':eid'      => $entidadeId,
            ':antes'    => $antes ? json_encode($antes) : null,
            ':depois'   => $depois ? json_encode($depois) : null,
            ':ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'       => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private function uuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    /**
     * POST /api/funcionarios/{id}/acesso-portal
     * Cria ou actualiza credenciais de acesso ao portal de ponto
     */
    public function criarAcessoPortal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $db   = $this->db();

        $stmt = $db->prepare("SELECT id, nome_completo, estado FROM funcionarios WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $func = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$func) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Funcionário não encontrado.']);
        }

        if (empty($body['email'])) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'O email é obrigatório para criar acesso ao portal.']);
        }

        if (empty($body['password']) || strlen($body['password']) < 6) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'A password deve ter pelo menos 6 caracteres.']);
        }

        // Verificar se email já existe
        $check = $db->prepare("SELECT id FROM utilizadores WHERE email = :email AND funcionario_id != :fid LIMIT 1");
        $check->execute([':email' => $body['email'], ':fid' => $id]);
        if ($check->fetch()) {
            return $this->json(409, ['erro' => true, 'mensagem' => 'Este email já está em uso por outro utilizador.']);
        }
	$perfil = in_array($body['perfil'] ?? '', ['funcionario', 'supervisor', 'rh_manager', 'rh_colaborador'])
	? $body['perfil']
	: 'funcionario';
        $hash   = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $activo = $func['estado'] === 'activo' ? 1 : 0;

        // Verificar se já tem utilizador associado
        $existing = $db->prepare("SELECT id FROM utilizadores WHERE funcionario_id = :fid LIMIT 1");
        $existing->execute([':fid' => $id]);
        $util = $existing->fetch(\PDO::FETCH_ASSOC);

        if ($util) {
            // Actualizar credenciais existentes
            $db->prepare("UPDATE utilizadores SET email = :email, password_hash = :hash, activo = :activo, perfil = :perfil WHERE id = :uid")
               ->execute([':email' => $body['email'], ':hash' => $hash, ':activo' => $activo, ':perfil' => $perfil, ':uid' => $util['id']]);
            $mensagem = 'Credenciais de acesso actualizadas com sucesso.';
        } else {
            // Criar novo utilizador
            $uuid = $this->uuid();
            $db->prepare("
                INSERT INTO utilizadores (uuid, nome, email, password_hash, perfil, funcionario_id, activo)
                VALUES (:uuid, :nome, :email, :hash, :perfil, :fid, :activo)
            ")->execute([
                ':uuid'   => $uuid,
                ':nome'   => $func['nome_completo'],
                ':email'  => $body['email'],
                ':hash'   => $hash,
                ':fid'    => $id,
		':perfil' => $perfil,
		':activo' => $activo,
            ]);
            $mensagem = 'Acesso ao portal criado com sucesso.';
        }

        // Actualizar email do funcionário se não tiver
        $db->prepare("UPDATE funcionarios SET email = :email WHERE id = :id AND (email IS NULL OR email = '')")
           ->execute([':email' => $body['email'], ':id' => $id]);

        $this->auditoria($db, $request, 'funcionario.acesso_portal', 'funcionario', $id, null, ['email' => $body['email']]);

        return $this->json(200, ['mensagem' => $mensagem]);
    }

    /**
     * PUT /api/funcionarios/{id}/acesso-portal/toggle
     * Activa ou desactiva acesso ao portal (sincronizado com estado do funcionário)
     */
    public function toggleAcessoPortal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) $args['id'];
        $body = $request->getParsedBody() ?? [];
        $db   = $this->db();

        $activo = isset($body['activo']) ? (int) $body['activo'] : null;

        if ($activo === null) {
            return $this->json(422, ['erro' => true, 'mensagem' => 'Campo activo (0 ou 1) é obrigatório.']);
        }

        $stmt = $db->prepare("UPDATE utilizadores SET activo = :activo WHERE funcionario_id = :fid");
        $stmt->execute([':activo' => $activo, ':fid' => $id]);

        if ($stmt->rowCount() === 0) {
            return $this->json(404, ['erro' => true, 'mensagem' => 'Este funcionário não tem acesso ao portal configurado.']);
        }

        $msg = $activo ? 'Acesso ao portal activado.' : 'Acesso ao portal desactivado.';
        return $this->json(200, ['mensagem' => $msg]);
    }

    /**
     * GET /api/funcionarios/{id}/acesso-portal
     * Verifica se o funcionário tem acesso ao portal e qual o estado
     */
    public function estadoAcessoPortal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (int) $args['id'];
        $db  = $this->db();

        $stmt = $db->prepare("SELECT id, email, activo, ultimo_login FROM utilizadores WHERE funcionario_id = :fid LIMIT 1");
        $stmt->execute([':fid' => $id]);
        $util = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$util) {
            return $this->json(200, ['tem_acesso' => false, 'dados' => null]);
        }

        return $this->json(200, [
            'tem_acesso' => true,
            'dados' => [
                'email'       => $util['email'],
                'activo'      => (bool) $util['activo'],
                'ultimo_login' => $util['ultimo_login'],
            ],
        ]);
    }

}
