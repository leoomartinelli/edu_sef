<?php
// api/models/Disciplina.php

require_once __DIR__ . '/../../config/Database.php';

class Disciplina
{
    private $conn;
    private $table_name = "disciplinas";
    private $turma_disciplina_table_name = "turma_disciplina";
    private $turmas_table_name = "turmas";

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Verifica se uma disciplina com o nome fornecido já existe na escola especificada.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function existsByNomeDisciplina($nomeDisciplina, $id_escola, $excludeId = null)
    {
        // <-- ALTERADO: Adicionada condição de id_escola
        $query = "SELECT COUNT(*) FROM " . $this->table_name . " WHERE nome_disciplina = :nome_disciplina AND id_escola = :id_escola";

        if ($excludeId !== null) {
            $query .= " AND id_disciplina != :exclude_id";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nome_disciplina", $nomeDisciplina);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

        if ($excludeId !== null) {
            $stmt->bindParam(":exclude_id", $excludeId, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Cria uma nova disciplina para uma escola específica.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function create($data, $id_escola)
    {
        if (empty($data['nome_disciplina'])) {
            return false;
        }

        if ($this->existsByNomeDisciplina($data['nome_disciplina'], $id_escola)) { // <-- ALTERADO
            return 'name_exists';
        }

        // <-- ALTERADO: Adicionado campo id_escola no INSERT
        $query = "INSERT INTO " . $this->table_name . " (nome_disciplina, id_escola) VALUES (:nome_disciplina, :id_escola)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nome_disciplina", $data['nome_disciplina']);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao criar disciplina: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Obtém todas as disciplinas de uma escola ou de todas (para superadmin).
     */
    // <-- ALTERADO: Adicionados parâmetros $id_escola e $userRole
    public function getAll($id_escola, $userRole)
    {
        $query = "SELECT id_disciplina, nome_disciplina FROM " . $this->table_name;

        // <-- ALTERADO: Filtro por escola
        if ($userRole !== 'superadmin') {
            $query .= " WHERE id_escola = :id_escola";
        }

        $query .= " ORDER BY nome_disciplina ASC";
        $stmt = $this->conn->prepare($query);

        if ($userRole !== 'superadmin') {
            $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtém uma disciplina por ID, garantindo que pertença à escola correta.
     */
    // <-- ALTERADO: Adicionados parâmetros $id_escola e $userRole
    public function getById($id, $id_escola, $userRole)
    {
        $query = "SELECT id_disciplina, nome_disciplina FROM " . $this->table_name . " WHERE id_disciplina = :id";

        // <-- ALTERADO: Filtro de segurança por escola
        if ($userRole !== 'superadmin') {
            $query .= " AND id_escola = :id_escola";
        }
        $query .= " LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id, PDO::PARAM_INT);

        if ($userRole !== 'superadmin') {
            $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza uma disciplina, garantindo que a operação seja feita na escola correta.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function update($data, $id_escola)
    {
        if (empty($data['id_disciplina']) || empty($data['nome_disciplina'])) {
            return false;
        }

        if ($this->existsByNomeDisciplina($data['nome_disciplina'], $id_escola, $data['id_disciplina'])) { // <-- ALTERADO
            return 'name_exists';
        }

        // <-- ALTERADO: Filtro de segurança por escola
        $query = "UPDATE " . $this->table_name . " SET nome_disciplina = :nome_disciplina WHERE id_disciplina = :id_disciplina AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nome_disciplina", $data['nome_disciplina']);
        $stmt->bindParam(":id_disciplina", $data['id_disciplina'], PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao atualizar disciplina: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Deleta uma disciplina, garantindo que a operação seja feita na escola correta.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function delete($id, $id_escola)
    {
        // A remoção de associações em 'turma_disciplina' acontecerá automaticamente
        // se a chave estrangeira foi configurada com ON DELETE CASCADE.
        // A segurança principal é garantir que a disciplina a ser deletada pertence à escola correta.

        // <-- ALTERADO: Filtro de segurança por escola
        $query = "DELETE FROM " . $this->table_name . " WHERE id_disciplina = :id AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id, PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

        if ($stmt->execute()) {
            // Verifica se alguma linha foi realmente deletada para confirmar a posse
            return $stmt->rowCount() > 0;
        }
        error_log("Erro ao deletar disciplina: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    // Os métodos de associação abaixo não precisam de grandes alterações no Model,
    // pois a segurança será garantida no Controller antes de chamá-los.

    public function getDisciplinasByTurmaId($idTurma)
    {
        // A verificação de que a turma pertence à escola correta será feita no Controller
        $query = "SELECT d.id_disciplina, d.nome_disciplina
                  FROM " . $this->turma_disciplina_table_name . " td
                  JOIN " . $this->table_name . " d ON td.id_disciplina = d.id_disciplina
                  WHERE td.id_turma = :id_turma
                  ORDER BY d.nome_disciplina ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_turma', $idTurma, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addDisciplinaToTurma($idTurma, $idDisciplina)
    {
        $checkQuery = "SELECT COUNT(*) FROM " . $this->turma_disciplina_table_name . " WHERE id_turma = :id_turma AND id_disciplina = :id_disciplina";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(":id_turma", $idTurma, PDO::PARAM_INT);
        $checkStmt->bindParam(":id_disciplina", $idDisciplina, PDO::PARAM_INT);
        $checkStmt->execute();
        if ($checkStmt->fetchColumn() > 0) {
            return 'already_exists';
        }

        $query = "INSERT INTO " . $this->turma_disciplina_table_name . " (id_turma, id_disciplina) VALUES (:id_turma, :id_disciplina)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_turma", $idTurma, PDO::PARAM_INT);
        $stmt->bindParam(":id_disciplina", $idDisciplina, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao associar disciplina à turma: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    public function removeDisciplinaFromTurma($idTurma, $idDisciplina)
    {
        $query = "DELETE FROM " . $this->turma_disciplina_table_name . " WHERE id_turma = :id_turma AND id_disciplina = :id_disciplina";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_turma", $idTurma, PDO::PARAM_INT);
        $stmt->bindParam(":id_disciplina", $idDisciplina, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao remover disciplina da turma: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    public function getTurmasByDisciplinaId($idDisciplina)
    {
        $query = "SELECT t.id_turma, t.nome_turma
                  FROM " . $this->turma_disciplina_table_name . " td
                  JOIN " . $this->turmas_table_name . " t ON td.id_turma = t.id_turma
                  WHERE td.id_disciplina = :id_disciplina
                  ORDER BY t.nome_turma ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_disciplina', $idDisciplina, PDO::PARAM_INT);
        try {
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar turmas da disciplina: " . $e->getMessage());
            return [];
        }
    }
}