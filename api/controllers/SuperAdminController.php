<?php
// api/controllers/SuperAdminController.php

require_once __DIR__ . '/../../config/Database.php';

class SuperAdminController
{
    private $db;

    public function __construct()
    {
        require_once __DIR__ . '/../../config/Database.php';
        $this->db = (new Database())->getConnection();
    }

    /**
     * Busca todas as escolas com suas estatísticas.
     */
    public function getAllEscolas()
    {
        try {
            // Query atualizada para incluir contagens
            $query = "
                SELECT
                    e.*,
                    u.username AS admin_username,
                    COUNT(DISTINCT a.id_aluno) AS total_alunos,
                    COUNT(DISTINCT pr.id_professor) AS total_professores,
                    COUNT(DISTINCT r_fin.id_responsavel) AS total_responsaveis_financeiros,
                    (
                        SELECT COUNT(DISTINCT p.id_responsavel)
                        FROM pendencias p
                        WHERE p.id_escola = e.id_escola
                    ) AS total_responsaveis_com_pendencias
                FROM
                    escolas e
                LEFT JOIN
                    usuarios u ON e.id_escola = u.id_escola AND u.role = 'admin'
                LEFT JOIN
                    alunos a ON e.id_escola = a.id_escola
                LEFT JOIN
                    professores pr ON e.id_escola = pr.id_escola
                LEFT JOIN
                    responsaveis r_fin ON e.id_escola = r_fin.id_escola
                GROUP BY
                    e.id_escola, u.username
                ORDER BY
                    e.nome_escola
            ";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $escolas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $escolas]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Cria uma nova escola e seu usuário admin principal.
     */
    public function createEscola()
    {
        $data = json_decode(file_get_contents('php://input'));

        if (
            !isset($data->nome_escola) || !isset($data->admin_username) || !isset($data->admin_password) ||
            empty($data->nome_escola) || empty($data->admin_username) || empty($data->admin_password)
        ) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Nome da escola, username e senha do admin são obrigatórios.']);
            return;
        }

        // Verifica se username já existe
        $stmt = $this->db->prepare("SELECT id_usuario FROM usuarios WHERE username = ?");
        $stmt->execute([$data->admin_username]);
        if ($stmt->fetch()) {
            http_response_code(409); // Conflict
            echo json_encode(['success' => false, 'message' => 'Este nome de usuário (username) já está em uso.']);
            return;
        }

        $this->db->beginTransaction();
        try {
            // 1. Criar a escola
            $stmt = $this->db->prepare("INSERT INTO escolas (nome_escola, endereco, telefone) VALUES (?, ?, ?)");
            $stmt->execute([
                $data->nome_escola,
                $data->endereco ?? null,
                $data->telefone ?? null
            ]);
            $id_escola = $this->db->lastInsertId();

            // 2. Criar o usuário admin para a escola
            $password_hash = password_hash($data->admin_password, PASSWORD_BCRYPT);
            $stmt = $this->db->prepare(
                "INSERT INTO usuarios (username, password_hash, role, id_escola, status) 
                 VALUES (?, ?, 'admin', ?, 'ativo')"
            );
            $stmt->execute([
                $data->admin_username,
                $password_hash,
                $id_escola
            ]);

            $this->db->commit();
            echo json_encode(['success' => true, 'message' => 'Escola e usuário admin criados com sucesso.', 'data' => ['id_escola' => $id_escola]]);
        } catch (Exception $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro ao criar escola: ' . $e->getMessage()]);
        }
    }

    /**
     * Atualiza os dados de uma escola.
     */
    public function updateEscola($id)
    {
        $data = json_decode(file_get_contents('php://input'));

        if (!isset($data->nome_escola) || empty($data->nome_escola)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Nome da escola é obrigatório.']);
            return;
        }

        try {
            $stmt = $this->db->prepare("UPDATE escolas SET nome_escola = ?, endereco = ?, telefone = ? WHERE id_escola = ?");
            $stmt->execute([
                $data->nome_escola,
                $data->endereco ?? null,
                $data->telefone ?? null,
                $id
            ]);

            echo json_encode(['success' => true, 'message' => 'Escola atualizada com sucesso.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro ao atualizar escola: ' . $e->getMessage()]);
        }
    }

    /**
     * Exclui uma escola e seu usuário admin principal.
     */
    public function deleteEscola($id)
    {
        // ATENÇÃO: Isso pode falhar se houver outros dados (alunos, turmas, etc.)
        // ligados a esta escola, devido às restrições de chave estrangeira.
        // Uma escola "vazia" pode ser deletada.
        $this->db->beginTransaction();
        try {
            // 1. Deletar usuários ligados à escola
            $stmt = $this->db->prepare("DELETE FROM usuarios WHERE id_escola = ?");
            $stmt->execute([$id]);

            // 2. Deletar a escola
            $stmt = $this->db->prepare("DELETE FROM escolas WHERE id_escola = ?");
            $stmt->execute([$id]);

            $this->db->commit();
            echo json_encode(['success' => true, 'message' => 'Escola e usuários associados deletados com sucesso.']);
        } catch (Exception $e) {
            $this->db->rollBack();
            http_response_code(500);
            // Mensagem de erro comum para FK
            if ($e->getCode() == '23000') {
                echo json_encode(['success' => false, 'message' => 'Não foi possível deletar. Esta escola possui dados (alunos, turmas, etc) associados. Remova todos os dados da escola antes de excluí-la.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Erro ao deletar escola: ' . $e->getMessage()]);
            }
        }
    }

    /**
     * Faz uma consulta de pendências global (sem filtro de escola).
     */
    public function consultarPendenciasGlobal()
    {
        $data = json_decode(file_get_contents('php://input'));
        $cpf = $data->cpf ?? null;

        if (!$cpf) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'CPF é obrigatório.']);
            return;
        }

        // Limpa o CPF
        $cpf = preg_replace('/\D/', '', $cpf);

        try {
            // Busca pendências E a qual escola elas pertencem
            $query = "
                SELECT 
                    p.*, 
                    r.nome AS nome_responsavel, 
                    e.nome_escola 
                FROM pendencias p
                JOIN responsaveis r ON p.id_responsavel = r.id_responsavel
                JOIN escolas e ON p.id_escola = e.id_escola
                WHERE r.cpf = ?
            ";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$cpf]);
            $pendencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($pendencias)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Nenhuma pendência encontrada para este CPF em nenhuma escola.']);
                return;
            }

            echo json_encode(['success' => true, 'data' => $pendencias, 'total_pendencias' => count($pendencias)]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Busca uma escola pelo ID com suas estatísticas.
     */
    public function getEscolaById($id)
    {
        try {
            // Query atualizada para incluir contagens
            $query = "
                SELECT
                    e.*,
                    u.username AS admin_username,
                    COUNT(DISTINCT a.id_aluno) AS total_alunos,
                    COUNT(DISTINCT pr.id_professor) AS total_professores,
                    COUNT(DISTINCT r_fin.id_responsavel) AS total_responsaveis_financeiros,
                    (
                        SELECT COUNT(DISTINCT p.id_responsavel)
                        FROM pendencias p
                        WHERE p.id_escola = e.id_escola
                    ) AS total_responsaveis_com_pendencias
                FROM
                    escolas e
                LEFT JOIN
                    usuarios u ON e.id_escola = u.id_escola AND u.role = 'admin'
                LEFT JOIN
                    alunos a ON e.id_escola = a.id_escola
                LEFT JOIN
                    professores pr ON e.id_escola = pr.id_escola
                LEFT JOIN
                    responsaveis r_fin ON e.id_escola = r_fin.id_escola
                WHERE
                    e.id_escola = ?
                GROUP BY
                    e.id_escola, u.username
            ";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$id]);
            $escola = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$escola) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Escola não encontrada.']);
                return;
            }

            echo json_encode(['success' => true, 'data' => $escola]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Faz uma consulta de aluno global por RA.
     */
    public function consultarAlunoGlobal()
    {
        $data = json_decode(file_get_contents('php://input'));
        $ra = $data->ra ?? null;

        if (!$ra || empty(trim($ra))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'RA é obrigatório.']);
            return;
        }

        try {
            // Adicionado LEFT JOIN com a tabela 'responsaveis'
            $query = "
                SELECT 
                    a.id_aluno, a.nome_aluno, a.ra_sef,
                    t.nome_turma,
                    e.id_escola, e.nome_escola,
                    r.nome AS nome_responsavel_financeiro
                FROM alunos a
                LEFT JOIN turmas t ON a.id_turma = t.id_turma
                JOIN escolas e ON a.id_escola = e.id_escola
                LEFT JOIN responsaveis r ON a.id_resp_financeiro = r.id_responsavel
                WHERE a.ra_sef = ?
            ";
            $stmt = $this->db->prepare($query);
            $stmt->execute([trim($ra)]);
            $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($alunos)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Nenhum aluno encontrado com este RA em nenhuma escola.']);
                return;
            }

            // Retorna os dados (pode ser mais de um se escolas diferentes usarem o mesmo RA)
            echo json_encode(['success' => true, 'data' => $alunos, 'total_encontrados' => count($alunos)]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}