<?php
// api/models/Turma.php

require_once __DIR__ . '/../../config/Database.php';

class Turma
{
    private $conn;
    private $table_name = "turmas";
    private $alunos_table_name = "alunos";

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Verifica se uma turma com o nome fornecido já existe na escola.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function existsByNomeTurma($nomeTurma, $id_escola, $excludeId = null)
    {
        // <-- ALTERADO: Adicionada condição de id_escola
        $query = "SELECT COUNT(*) FROM " . $this->table_name . " WHERE nome_turma = :nome_turma AND id_escola = :id_escola";

        if ($excludeId !== null) {
            $query .= " AND id_turma != :exclude_id";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":nome_turma", $nomeTurma);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

        if ($excludeId !== null) {
            $stmt->bindParam(":exclude_id", $excludeId, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Cria uma nova turma no banco de dados para uma escola específica.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function create($data, $id_escola)
    {
        if (empty($data['nome_turma'])) {
            return false;
        }

        if ($this->existsByNomeTurma($data['nome_turma'], $id_escola)) { // <-- ALTERADO
            error_log("Tentativa de criar turma com nome existente: " . $data['nome_turma']);
            return 'nome_exists';
        }

        // <-- ALTERADO: Adicionado campo id_escola
        $query = "INSERT INTO " . $this->table_name . " (nome_turma, periodo, ano_letivo, descricao, id_escola) VALUES (:nome_turma, :periodo, :ano_letivo, :descricao, :id_escola)";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":nome_turma", $data['nome_turma']);
        $stmt->bindParam(":periodo", $data['periodo']);
        $stmt->bindParam(":ano_letivo", $data['ano_letivo'], PDO::PARAM_INT);
        $stmt->bindParam(":descricao", $data['descricao']);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        error_log("Erro ao criar turma: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Obtém todas as turmas da escola.
     */
    // <-- ALTERADO: Adicionados parâmetros de autenticação
    public function getAll($id_escola, $userRole)
    {
        $query = "SELECT 
                    t.id_turma, t.nome_turma, t.periodo, t.ano_letivo, t.descricao,
                    (SELECT COUNT(*) FROM " . $this->alunos_table_name . " a WHERE a.id_turma = t.id_turma) AS quantidade_alunos
                  FROM " . $this->table_name . " t";

        $params = [];
        // <-- ALTERADO: Filtro por escola
        if ($userRole !== 'superadmin') {
            $query .= " WHERE t.id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        $query .= " ORDER BY t.nome_turma ASC";
        $stmt = $this->conn->prepare($query);
        try {
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar turmas: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtém uma única turma por ID, verificando se pertence à escola.
     */
    // <-- ALTERADO: Função corrigida para aceitar 3 parâmetros
    public function getById($id, $id_escola, $userRole)
    {
        $query = "SELECT 
                    t.id_turma, t.nome_turma, t.periodo, t.ano_letivo, t.descricao,
                    (SELECT COUNT(*) FROM " . $this->alunos_table_name . " a WHERE a.id_turma = t.id_turma) AS quantidade_alunos
                  FROM " . $this->table_name . " t 
                  WHERE t.id_turma = :id";

        // <-- ALTERADO: Filtro de segurança por escola
        if ($userRole !== 'superadmin') {
            $query .= " AND t.id_escola = :id_escola";
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
     * Atualiza uma turma existente no banco de dados.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function update($data, $id_escola)
    {
        if (empty($data['id_turma']) || empty($data['nome_turma'])) {
            return false;
        }

        if ($this->existsByNomeTurma($data['nome_turma'], $id_escola, $data['id_turma'])) { // <-- ALTERADO
            error_log("Tentativa de atualizar turma com nome já existente em outra turma: " . $data['nome_turma']);
            return 'nome_exists';
        }

        // <-- ALTERADO: Filtro de segurança por escola
        $query = "UPDATE " . $this->table_name . " SET nome_turma = :nome_turma, periodo = :periodo, ano_letivo = :ano_letivo, descricao = :descricao WHERE id_turma = :id_turma AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":nome_turma", $data['nome_turma']);
        $stmt->bindParam(":periodo", $data['periodo']);
        $stmt->bindParam(":ano_letivo", $data['ano_letivo'], PDO::PARAM_INT);
        $stmt->bindParam(":descricao", $data['descricao']);
        $stmt->bindParam(":id_turma", $data['id_turma'], PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao atualizar turma: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Deleta uma turma do banco de dados.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function delete($id, $id_escola)
    {
        // <-- ALTERADO: Filtro de segurança por escola
        $updateAlunosQuery = "UPDATE " . $this->alunos_table_name . " SET id_turma = NULL WHERE id_turma = :id_turma AND id_escola = :id_escola";
        $updateAlunosStmt = $this->conn->prepare($updateAlunosQuery);
        $updateAlunosStmt->bindParam(":id_turma", $id, PDO::PARAM_INT);
        $updateAlunosStmt->bindParam(":id_escola", $id, PDO::PARAM_INT);
        $updateAlunosStmt->execute();

        // <-- ALTERADO: Filtro de segurança por escola
        $query = "DELETE FROM " . $this->table_name . " WHERE id_turma = :id AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id, PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return $stmt->rowCount() > 0;
        }
        error_log("Erro ao deletar turma: " . implode(" ", $stmt->errorInfo()));
        return false;
    }
    /**
     * Encontra uma turma pelo nome para uma dada escola, ou a cria se não existir.
     * Retorna o ID da turma.
     */
    public function findOrCreateByNome($nomeTurma, $id_escola, $dadosAdicionais = [])
    {
        // 1. Tenta encontrar a turma pelo nome e escola
        $query = "SELECT id_turma FROM " . $this->table_name . " WHERE nome_turma = :nome_turma AND id_escola = :id_escola LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':nome_turma', $nomeTurma);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        $stmt->execute();
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($turma) {
            return (int) $turma['id_turma'];
        }

        // 2. Se não encontrou, cria a turma
        $createQuery = "INSERT INTO " . $this->table_name .
            " (nome_turma, id_escola, periodo, ano_letivo, descricao) VALUES (:nome_turma, :id_escola, :periodo, :ano_letivo, :descricao)";
        $createStmt = $this->conn->prepare($createQuery);

        // Define valores padrão se não forem fornecidos na planilha
        $periodo = $dadosAdicionais['periodo'] ?? 'Não informado';
        $ano_letivo = $dadosAdicionais['ano_letivo'] ?? date('Y');
        $descricao = $dadosAdicionais['descricao'] ?? null;

        $createStmt->bindParam(':nome_turma', $nomeTurma);
        $createStmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        $createStmt->bindParam(':periodo', $periodo);
        $createStmt->bindParam(':ano_letivo', $ano_letivo);
        $createStmt->bindParam(':descricao', $descricao);

        if ($createStmt->execute()) {
            return (int) $this->conn->lastInsertId();
        }

        return null; // Retorna null em caso de falha na criação
    }

    public function getProfessoresByTurmaId($idTurma)
    {
        $query = "SELECT p.id_professor, p.nome_professor
                  FROM professor_turma pt
                  JOIN professores p ON pt.id_professor = p.id_professor
                  WHERE pt.id_turma = :id_turma";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_turma', $idTurma, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}