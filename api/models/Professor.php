
<?php
// api/models/Professor.php

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../models/Usuario.php'; // Para gerenciar o usuário de login do professor

class Professor
{
    private $conn;
    private $table_name = "professores";
    private $usuarios_table_name = "usuarios";
    private $professor_turma_table_name = "professor_turma"; // Nova tabela pivô
    private $turmas_table_name = "turmas"; // Tabela de turmas
    private $usuarioModel;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->usuarioModel = new Usuario();
    }

    /**
     * Limpa uma string, removendo todos os caracteres não numéricos.
     * @param string|null $number O número a ser limpo.
     * @return string|null O número contendo apenas dígitos.
     */
    private function cleanNumber($number)
    {
        return $number ? preg_replace('/\D/', '', $number) : null;
    }

    /**
     * Verifica se um professor com o CPF fornecido já existe.
     * @param string $cpf O CPF a ser verificado.
     * @param int|null $excludeId O ID do professor a ser excluído da verificação (para atualizações).
     * @return bool Retorna true se o CPF já existe, false caso contrário.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function existsByCpf($cpf, $id_escola, $excludeId = null)
    {
        // <-- ALTERADO: Adicionada condição de id_escola
        $query = "SELECT COUNT(*) FROM " . $this->table_name . " WHERE cpf = :cpf AND id_escola = :id_escola";

        if ($excludeId !== null) {
            $query .= " AND id_professor != :exclude_id";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":cpf", $cpf);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

        if ($excludeId !== null) {
            $stmt->bindParam(":exclude_id", $excludeId, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Cria um novo professor no banco de dados, incluindo a criação de um usuário de login.
     * @param array $data Os dados do professor a serem inseridos.
     * @return bool|string Retorna true em caso de sucesso, 'cpf_exists' se o CPF já existir, ou false em caso de erro.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function create($data, $id_escola)
    {
        // Validação básica
        if (empty($data['nome_professor']) || empty($data['cpf'])) {
            return false;
        }

        $cleanedCpf = $this->cleanNumber($data['cpf']);
        if ($this->existsByCpf($cleanedCpf, $id_escola)) { // <-- ALTERADO
            error_log("Tentativa de criar professor com CPF existente: " . $cleanedCpf);
            return 'cpf_exists';
        }

        try {
            $this->conn->beginTransaction();

            // 1. Cria o usuário de login
            $username = $cleanedCpf;
            $password = substr($cleanedCpf, 0, 6);
            $role = 'professor';

            $userCreated = $this->usuarioModel->create([
                'username' => $username,
                'password' => $password,
                'role' => $role,
                'id_escola' => $id_escola // <-- ALTERADO: Associa usuário à escola
            ]);

            if (!$userCreated) {
                throw new Exception("Falha ao criar usuário para o professor. O username (CPF) pode já existir na tabela de usuários.");
            }

            $usuario = $this->usuarioModel->findByUsername($username);
            if (!$usuario) {
                throw new Exception("Erro interno: Usuário criado mas não encontrado para obter ID.");
            }
            $id_usuario = $usuario['id_usuario'];

            // 2. Insere os dados do professor
            // <-- ALTERADO: Adicionado campo id_escola
            $query = "INSERT INTO " . $this->table_name . " (nome_professor, email, telefone, cpf, data_contratacao, id_usuario, id_escola) VALUES (:nome_professor, :email, :telefone, :cpf, :data_contratacao, :id_usuario, :id_escola)";
            $stmt = $this->conn->prepare($query);
            
            $cleanedTelefone = $this->cleanNumber($data['telefone']);

            $stmt->bindParam(":nome_professor", $data['nome_professor']);
            $stmt->bindParam(":email", $data['email']);
            $stmt->bindParam(":telefone", $cleanedTelefone);
            $stmt->bindParam(":cpf", $cleanedCpf);
            $stmt->bindParam(":data_contratacao", $data['data_contratacao']);
            $stmt->bindParam(":id_usuario", $id_usuario, PDO::PARAM_INT);
            $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

            if (!$stmt->execute()) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Erro ao inserir dados do professor: " . implode(" ", $errorInfo));
            }

            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Erro ao criar professor e usuário: " . $e->getMessage());
            if (isset($username) && $this->usuarioModel->findByUsername($username)) {
                $this->usuarioModel->deleteByUsername($username);
            }
            return false;
        }
    }

    /**
     * Obtém todos os professores do banco de dados, com turmas associadas.
     * @return array Retorna um array de professores.
     */
    // <-- ALTERADO: Adicionados parâmetros de autenticação
    public function getAll($id_escola, $userRole)
    {
        $query = "SELECT p.id_professor, p.nome_professor, p.email, p.telefone, p.cpf, p.data_contratacao, u.username
                  FROM " . $this->table_name . " p
                  LEFT JOIN " . $this->usuarios_table_name . " u ON p.id_usuario = u.id_usuario";
        
        $params = [];
        // <-- ALTERADO: Filtro por escola
        if ($userRole !== 'superadmin') {
            $query .= " WHERE p.id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        $query .= " ORDER BY p.nome_professor ASC";
        $stmt = $this->conn->prepare($query);
        try {
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar professores: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtém um único professor por ID.
     * @param int $id O ID do professor.
     * @return array|false Retorna os dados do professor ou false se não encontrado.
     */
    // <-- ALTERADO: Adicionados parâmetros de autenticação
    public function getById($id, $id_escola, $userRole)
    {
        $query = "SELECT p.*, u.username FROM " . $this->table_name . " p
                  LEFT JOIN " . $this->usuarios_table_name . " u ON p.id_usuario = u.id_usuario
                  WHERE p.id_professor = :id";
        
        // <-- ALTERADO: Filtro de segurança
        if ($userRole !== 'superadmin') {
            $query .= " AND p.id_escola = :id_escola";
        }
        $query .= " LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        if ($userRole !== 'superadmin') {
            $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza um professor existente no banco de dados.
     * @param array $data Os dados do professor a serem atualizados.
     * @return bool|string Retorna true em caso de sucesso, 'cpf_exists' se o CPF já existir em outro professor, ou false em caso de erro.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function update($data, $id_escola)
    {
        if (empty($data['id_professor']) || empty($data['nome_professor']) || empty($data['cpf'])) {
            return false;
        }

        $cleanedCpf = $this->cleanNumber($data['cpf']);
        // Verifica a unicidade do CPF (excluindo o próprio professor)
        if ($this->existsByCpf($cleanedCpf, $id_escola, $data['id_professor'])) { // <-- ALTERADO
            error_log("Tentativa de atualizar professor com CPF já existente em outro professor: " . $cleanedCpf);
            return 'cpf_exists';
        }

        try {
            $this->conn->beginTransaction();

            // <-- ALTERADO: Adicionado filtro de segurança
            $query = "UPDATE " . $this->table_name . " SET nome_professor = :nome_professor, email = :email, telefone = :telefone, cpf = :cpf, data_contratacao = :data_contratacao WHERE id_professor = :id_professor AND id_escola = :id_escola";
            $stmt = $this->conn->prepare($query);

            $stmt->bindParam(":nome_professor", $data['nome_professor']);
            $stmt->bindParam(":email", $data['email']);
            $stmt->bindParam(":telefone", $this->cleanNumber($data['telefone']));
            $stmt->bindParam(":cpf", $cleanedCpf);
            $stmt->bindParam(":data_contratacao", $data['data_contratacao']);
            $stmt->bindParam(":id_professor", $data['id_professor'], PDO::PARAM_INT);
            $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

            if (!$stmt->execute()) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Erro ao atualizar dados do professor: " . implode(" ", $errorInfo));
            }

            // Opcional: Atualizar o username do usuário se o CPF mudou
            $professorAtual = $this->getById($data['id_professor'], $id_escola, 'admin'); // Força a busca na escola
            if ($professorAtual && $professorAtual['id_usuario']) {
                $usuarioAntigo = $this->usuarioModel->findByUsername($professorAtual['username']);
                if ($usuarioAntigo && $usuarioAntigo['username'] !== $cleanedCpf) {
                    $updateUserQuery = "UPDATE " . $this->usuarios_table_name . " SET username = :new_username WHERE id_usuario = :id_usuario";
                    $updateUserStmt = $this->conn->prepare($updateUserQuery);
                    $updateUserStmt->bindParam(":new_username", $cleanedCpf);
                    $updateUserStmt->bindParam(":id_usuario", $professorAtual['id_usuario'], PDO::PARAM_INT);
                    if (!$updateUserStmt->execute()) {
                        $updateError = $updateUserStmt->errorInfo();
                        throw new Exception("Erro ao atualizar username do usuário: " . implode(" ", $updateError));
                    }
                }
            }

            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Erro ao atualizar professor: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deleta um professor e todas as suas associações.
     * @param int $id O ID do professor a ser deletado.
     * @return bool Retorna true em caso de sucesso, false em caso de erro.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function delete($id, $id_escola)
    {
        try {
            $this->conn->beginTransaction();

            // <-- ALTERADO: Filtro de segurança
            $query = "DELETE FROM " . $this->table_name . " WHERE id_professor = :id AND id_escola = :id_escola";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id, PDO::PARAM_INT);
            $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO
            
            if(!$stmt->execute() || $stmt->rowCount() == 0){
                throw new PDOException("Professor não encontrado ou não pertence à escola.");
            }

            $this->conn->commit();
            return true;

        } catch (PDOException $e) {
            $this->conn->rollBack();
            if (strpos($e->getMessage(), 'foreign key constraint fails') !== false) {
                return 'in_use'; // Professor associado a uma turma
            }
            error_log("Erro ao deletar professor: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtém as turmas associadas a um professor.
     * @param int $idProfessor O ID do professor.
     * @return array Retorna um array de turmas.
     */
    public function getTurmasByProfessorId($idProfessor)
    {
        $query = "SELECT t.id_turma, t.nome_turma, pt.data_inicio_lecionar, pt.data_fim_lecionar
                  FROM " . $this->professor_turma_table_name . " pt
                  JOIN " . $this->turmas_table_name . " t ON pt.id_turma = t.id_turma
                  WHERE pt.id_professor = :id_professor
                  ORDER BY t.nome_turma ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_professor', $idProfessor, PDO::PARAM_INT);
        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar turmas do professor: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Associa um professor a uma turma.
     */
    public function addTurmaToProfessor($idProfessor, $idTurma, $dataInicioLecionar, $dataFimLecionar = null)
    {
        // Verifica se a associação já existe
        $checkQuery = "SELECT COUNT(*) FROM " . $this->professor_turma_table_name . " WHERE id_professor = :id_professor AND id_turma = :id_turma";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(":id_professor", $idProfessor, PDO::PARAM_INT);
        $checkStmt->bindParam(":id_turma", $idTurma, PDO::PARAM_INT);
        $checkStmt->execute();
        if ($checkStmt->fetchColumn() > 0) {
            return 'already_exists'; // Já existe
        }

        $query = "INSERT INTO " . $this->professor_turma_table_name . " (id_professor, id_turma, data_inicio_lecionar, data_fim_lecionar) VALUES (:id_professor, :id_turma, :data_inicio_lecionar, :data_fim_lecionar)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_professor", $idProfessor, PDO::PARAM_INT);
        $stmt->bindParam(":id_turma", $idTurma, PDO::PARAM_INT);
        $stmt->bindParam(":data_inicio_lecionar", $dataInicioLecionar);
        $stmt->bindParam(":data_fim_lecionar", $dataFimLecionar);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao adicionar turma ao professor: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Remove a associação de uma turma a um professor.
     */
    /**
     * Remove a associação de uma turma a um professor.
     * @param int $idProfessor
     * @param int $idTurma
     * @return bool True em sucesso, false em falha.
     */
    public function removeTurmaFromProfessor($idProfessor, $idTurma)
    {
        $query = "DELETE FROM " . $this->professor_turma_table_name . " WHERE id_professor = :id_professor AND id_turma = :id_turma";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_professor", $idProfessor, PDO::PARAM_INT);
        $stmt->bindParam(":id_turma", $idTurma, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao remover turma do professor: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Busca um professor pelo seu id_usuario.
     */
    public function getProfessorByUserId($idUsuario)
    {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id_usuario = :id_usuario LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_usuario', $idUsuario, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna o número total de professores cadastrados na escola.
     */
    // <-- ALTERADO: Adicionados parâmetros de autenticação
    public function getTotalCount($id_escola, $userRole)
    {
        $query = "SELECT COUNT(id_professor) as total FROM " . $this->table_name;
        $params = [];
        if ($userRole !== 'superadmin') {
            $query .= " WHERE id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }
        $stmt = $this->conn->prepare($query);
        if ($stmt->execute($params)) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return (int) $row['total'];
            }
        }
        return 0;
    }

    /**
     * Busca um professor pelo ID do usuário associado.
     */
    public function getByUserId($idUsuario)
    {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id_usuario = :id_usuario LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_usuario', $idUsuario, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getProfessorTurmas($idProfessor)
    {
        $query = "SELECT t.id_turma, t.nome_turma
                  FROM turmas t
                  JOIN professor_turma pt ON t.id_turma = pt.id_turma
                  WHERE pt.id_professor = :id_professor
                  ORDER BY t.nome_turma ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_professor', $idProfessor, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}