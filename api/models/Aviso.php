<?php
// api/models/Aviso.php

require_once __DIR__ . '/../../config/Database.php';

class Aviso
{
    private $conn;
    private $table = 'avisos';

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function create($titulo, $mensagem, $idUsuarioCriador, $id_escola, $idTurma = null)
    {
        // <-- ALTERADO: Adicionado campo id_escola no INSERT
        $query = "INSERT INTO " . $this->table . " (titulo, mensagem, id_usuario_criador, id_turma, id_escola) VALUES (:titulo, :mensagem, :id_usuario_criador, :id_turma, :id_escola)";
        $stmt = $this->conn->prepare($query);

        $titulo = htmlspecialchars(strip_tags($titulo));
        $mensagem = htmlspecialchars(strip_tags($mensagem));
        $idUsuarioCriador = (int) $idUsuarioCriador;
        $idTurma = $idTurma ? (int) $idTurma : null;

        $stmt->bindParam(':titulo', $titulo);
        $stmt->bindParam(':mensagem', $mensagem);
        $stmt->bindParam(':id_usuario_criador', $idUsuarioCriador);
        $stmt->bindParam(':id_turma', $idTurma);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT); // <-- ALTERADO: Bind do id_escola

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        error_log("Erro ao criar aviso: " . implode(" ", $stmt->errorInfo())); // <-- ALTERADO: Adicionado log de erro
        return false;
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function getAllByUserData($userData, $id_escola)
    {
        $role = $userData['role'];
        $idUsuario = $userData['id_usuario'];

        $query = "SELECT 
                    a.id_aviso, a.titulo, a.mensagem, a.data_criacao,
                    COALESCE(p.nome_professor, u.username) as nome_criador, 
                    t.nome_turma 
                  FROM " . $this->table . " a 
                  JOIN usuarios u ON a.id_usuario_criador = u.id_usuario 
                  LEFT JOIN professores p ON u.id_usuario = p.id_usuario
                  LEFT JOIN turmas t ON a.id_turma = t.id_turma";

        $conditions = [];
        $params = [];

        // <-- ALTERADO: Filtro principal por id_escola para todos os perfis
        if ($role !== 'superadmin') {
            $conditions[] = "a.id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        if ($role === 'professor') {
            require_once 'Professor.php';
            $professorModel = new Professor();
            $professor = $professorModel->getByUserId($idUsuario);
            if (!$professor)
                return [];
            $idProfessor = $professor['id_professor'];

            $conditions[] = "(a.id_turma IS NULL OR a.id_turma IN (SELECT id_turma FROM professor_turma WHERE id_professor = :id_professor))";
            $params[':id_professor'] = $idProfessor;

        } elseif ($role === 'aluno') {
            require_once 'Aluno.php';
            $alunoModel = new Aluno();
            $aluno = $alunoModel->getByUserId($idUsuario);
            if (!$aluno || !$aluno['id_turma'])
                return [];
            $idTurmaAluno = $aluno['id_turma'];

            $conditions[] = "(a.id_turma IS NULL OR a.id_turma = :id_turma)";
            $params[':id_turma'] = $idTurmaAluno;
        }

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }

        $query .= " ORDER BY a.data_criacao DESC";

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => &$val) {
            $stmt->bindParam($key, $val);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola para segurança
    public function delete($idAviso, $id_escola)
    {
        // <-- ALTERADO: Condição de segurança com id_escola
        $query = "DELETE FROM " . $this->table . " WHERE id_aviso = :id_aviso AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_aviso', $idAviso, PDO::PARAM_INT);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT); // <-- ALTERADO
        return $stmt->execute();
    }
}