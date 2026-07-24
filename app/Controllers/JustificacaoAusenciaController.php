<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\TenantResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class JustificacaoAusenciaController
{
    private function db(): PDO
    {
        $sub = TenantResolver::resolve() ?? ($_SERVER['HTTP_X_TENANT'] ?? null);
        return Database::tenant($sub);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $estado = $params['estado'] ?? null;
        $db = $this->db();

        $query = "
            SELECT j.*, f.nome_completo as funcionario_nome, f.numero_funcionario as funcionario_numero, f.departamento_id
            FROM justificacoes_ausencia j
            JOIN funcionarios f ON j.funcionario_id = f.id
            WHERE 1=1
        ";
        $bind = [];

        if ($estado) {
            $query .= " AND j.estado = :estado";
            $bind[':estado'] = $estado;
        }

        $query .= " ORDER BY j.criado_em DESC";

        $stmt = $db->prepare($query);
        $stmt->execute($bind);
        $justificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode(['erro' => false, 'dados' => $justificacoes]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $request->getParsedBody();
        $db = $this->db();

        $funcionarioId = (int)($data['funcionario_id'] ?? 0);
        $dataInicio = $data['data_inicio'] ?? '';
        $dataFim = $data['data_fim'] ?? '';
        $tipo = $data['tipo'] ?? '';
        $motivo = $data['motivo'] ?? null;
        $nota = $data['nota'] ?? null;
        $documentoUrl = $data['documento_url'] ?? null;

        if (!$funcionarioId || !$dataInicio || !$dataFim || !$tipo) {
            $response->getBody()->write(json_encode(['erro' => true, 'mensagem' => 'Dados incompletos.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $criadoPor = (int)($request->getAttribute('auth_user')->id ?? 0);

        $stmt = $db->prepare("
            INSERT INTO justificacoes_ausencia
            (funcionario_id, data_inicio, data_fim, tipo, motivo, nota, documento_url, estado, criado_por)
            VALUES (:fid, :dini, :dfim, :tipo, :motivo, :nota, :doc, 'pendente', :criado_por)
        ");

        $stmt->execute([
            ':fid' => $funcionarioId,
            ':dini' => $dataInicio,
            ':dfim' => $dataFim,
            ':tipo' => $tipo,
            ':motivo' => $tipo === 'falta_justificada' ? $motivo : null,
            ':nota' => $nota,
            ':doc' => $documentoUrl,
            ':criado_por' => $criadoPor
        ]);

        $response->getBody()->write(json_encode(['erro' => false, 'mensagem' => 'Justificação submetida com sucesso.']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateEstado(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)$args['id'];
        $data = json_decode((string)$request->getBody(), true);
        $estado = $data['estado'] ?? '';
        $motivoRejeicao = $data['motivo_rejeicao'] ?? null;
        $db = $this->db();

        if (!in_array($estado, ['aprovado', 'rejeitado'])) {
            $response->getBody()->write(json_encode(['erro' => true, 'mensagem' => 'Estado inválido.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $aprovadoPor = (int)($request->getAttribute('auth_user')->id ?? 0);

        $stmt = $db->prepare("
            UPDATE justificacoes_ausencia
            SET estado = :estado, aprovado_por = :apor, aprovado_em = NOW(), motivo_rejeicao = :motivo
            WHERE id = :id
        ");

        $stmt->execute([
            ':estado' => $estado,
            ':apor' => $aprovadoPor,
            ':motivo' => $estado === 'rejeitado' ? $motivoRejeicao : null,
            ':id' => $id
        ]);

        $response->getBody()->write(json_encode(['erro' => false, 'mensagem' => 'Estado atualizado.']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uploadedFiles = $request->getUploadedFiles();
        if (empty($uploadedFiles['documento'])) {
            $response->getBody()->write(json_encode(['erro' => true, 'mensagem' => 'Nenhum ficheiro recebido.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $documento = $uploadedFiles['documento'];
        if ($documento->getError() !== UPLOAD_ERR_OK) {
            $response->getBody()->write(json_encode(['erro' => true, 'mensagem' => 'Erro no upload.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $extension = pathinfo($documento->getClientFilename(), PATHINFO_EXTENSION);
        if (!in_array(strtolower($extension), ['pdf', 'jpg', 'jpeg', 'png'])) {
            $response->getBody()->write(json_encode(['erro' => true, 'mensagem' => 'Formato não suportado. Use PDF, JPG ou PNG.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        if ($documento->getSize() > 5 * 1024 * 1024) { // 5MB
            $response->getBody()->write(json_encode(['erro' => true, 'mensagem' => 'O ficheiro excede 5MB.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $tenant = TenantResolver::resolve() ?? 'default';
        $uploadDir = __DIR__ . '/../../storage/justificacoes/' . $tenant . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $filename = uniqid('just_') . '.' . $extension;
        $documento->moveTo($uploadDir . $filename);

        $url = '/api/justificacoes-ausencia/documento/' . $filename;

        $response->getBody()->write(json_encode(['erro' => false, 'url' => $url]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function downloadDocumento(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $filename = $args['filename'];
        $tenant = TenantResolver::resolve() ?? 'default';
        $filepath = __DIR__ . '/../../storage/justificacoes/' . $tenant . '/' . basename($filename);

        if (!file_exists($filepath)) {
            $response->getBody()->write('File not found');
            return $response->withStatus(404);
        }

        $extension = pathinfo($filepath, PATHINFO_EXTENSION);
        $contentType = match(strtolower($extension)) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream'
        };

        $response->getBody()->write(file_get_contents($filepath));
        return $response->withHeader('Content-Type', $contentType);
    }
}
