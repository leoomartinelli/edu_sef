<?php
// api/models/Boletim.php

require_once __DIR__ . '/../../config/Database.php';

class Boletim
{
    private $conn;
    private $table_name = "boletim_aluno";
    private $disciplinas_table_name = "disciplinas";
    private $alunos_table_name = "alunos";

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Obtém o boletim completo de um aluno para um dado ano, restrito por escola.
     * @param int $idAluno
     * @param int $anoLetivo
     * @param int $id_escola
     * @return array
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function getBoletimByAlunoAndAno($idAluno, $anoLetivo, $id_escola)
    {
        $query = "SELECT
                    ba.*,
                    d.nome_disciplina
                  FROM " . $this->table_name . " ba
                  JOIN " . $this->disciplinas_table_name . " d ON ba.id_disciplina = d.id_disciplina
                  WHERE ba.id_aluno = :id_aluno 
                  AND ba.ano_letivo = :ano_letivo
                  AND ba.id_escola = :id_escola"; // <-- ALTERADO: Filtro de segurança

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_aluno', $idAluno, PDO::PARAM_INT);
        $stmt->bindParam(':ano_letivo', $anoLetivo, PDO::PARAM_INT);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT); // <-- ALTERADO
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cria ou atualiza um registro de boletim.
     * @param array $data Contém os dados do boletim.
     * @param int $id_escola O ID da escola do usuário.
     * @return bool
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function saveBoletimEntry($data, $id_escola)
    {
        $existingEntry = $this->getBoletimEntry($data['id_aluno'], $data['ano_letivo'], $data['id_disciplina'], $id_escola);

        if ($existingEntry) {
            return $this->updateBoletimEntry($data, $id_escola);
        } else {
            return $this->createBoletimEntry($data, $id_escola);
        }
    }

    /**
     * Busca um único registro de boletim.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    private function getBoletimEntry($idAluno, $anoLetivo, $idDisciplina, $id_escola)
    {
        $query = "SELECT * FROM " . $this->table_name . "
                  WHERE id_aluno = :id_aluno 
                  AND ano_letivo = :ano_letivo 
                  AND id_disciplina = :id_disciplina
                  AND id_escola = :id_escola LIMIT 1"; // <-- ALTERADO
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_aluno', $idAluno, PDO::PARAM_INT);
        $stmt->bindParam(':ano_letivo', $anoLetivo, PDO::PARAM_INT);
        $stmt->bindParam(':id_disciplina', $idDisciplina, PDO::PARAM_INT);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT); // <-- ALTERADO
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Cria um novo registro de boletim.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    private function createBoletimEntry($data, $id_escola)
    {
        // <-- ALTERADO: Adicionado campo id_escola no INSERT
        $query = "INSERT INTO " . $this->table_name . " (
                    id_aluno, id_turma, ano_letivo, id_disciplina, id_escola,
                    nota_1b, faltas_1b, nota_2b, faltas_2b,
                    nota_3b, faltas_3b, nota_4b, faltas_4b,
                    media_final, frequencia_final, recuperacao_final, resultado_final, observacoes
                  ) VALUES (
                    :id_aluno, :id_turma, :ano_letivo, :id_disciplina, :id_escola,
                    :nota_1b, :faltas_1b, :nota_2b, :faltas_2b,
                    :nota_3b, :faltas_3b, :nota_4b, :faltas_4b,
                    :media_final, :frequencia_final, :recuperacao_final, :resultado_final, :observacoes
                  )";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_aluno", $data['id_aluno'], PDO::PARAM_INT);
        $stmt->bindParam(":id_turma", $data['id_turma'], PDO::PARAM_INT);
        $stmt->bindParam(":ano_letivo", $data['ano_letivo'], PDO::PARAM_INT);
        $stmt->bindParam(":id_disciplina", $data['id_disciplina'], PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

        $this->bindBoletimOptionalParams($stmt, $data);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao criar entrada de boletim: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Atualiza um registro de boletim existente.
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    private function updateBoletimEntry($data, $id_escola)
    {
        $query = "UPDATE " . $this->table_name . " SET
                    nota_1b = :nota_1b, faltas_1b = :faltas_1b,
                    nota_2b = :nota_2b, faltas_2b = :faltas_2b,
                    nota_3b = :nota_3b, faltas_3b = :faltas_3b,
                    nota_4b = :nota_4b, faltas_4b = :faltas_4b,
                    media_final = :media_final,
                    frequencia_final = :frequencia_final,
                    recuperacao_final = :recuperacao_final,
                    resultado_final = :resultado_final,
                    observacoes = :observacoes
                  WHERE id_aluno = :id_aluno 
                  AND ano_letivo = :ano_letivo 
                  AND id_disciplina = :id_disciplina
                  AND id_escola = :id_escola"; // <-- ALTERADO: Filtro de segurança

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_aluno", $data['id_aluno'], PDO::PARAM_INT);
        $stmt->bindParam(":ano_letivo", $data['ano_letivo'], PDO::PARAM_INT);
        $stmt->bindParam(":id_disciplina", $data['id_disciplina'], PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

        $this->bindBoletimOptionalParams($stmt, $data);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao atualizar entrada de boletim: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    /**
     * Ajuda a bindar os parâmetros OPCIONAIS para criar/atualizar boletim.
     */
    private function bindBoletimOptionalParams($stmt, $data)
    {
        $stmt->bindParam(":nota_1b", $data['nota_1b'], PDO::PARAM_STR);
        $stmt->bindParam(":faltas_1b", $data['faltas_1b'], PDO::PARAM_INT);
        $stmt->bindParam(":nota_2b", $data['nota_2b'], PDO::PARAM_STR);
        $stmt->bindParam(":faltas_2b", $data['faltas_2b'], PDO::PARAM_INT);
        $stmt->bindParam(":nota_3b", $data['nota_3b'], PDO::PARAM_STR);
        $stmt->bindParam(":faltas_3b", $data['faltas_3b'], PDO::PARAM_INT);
        $stmt->bindParam(":nota_4b", $data['nota_4b'], PDO::PARAM_STR);
        $stmt->bindParam(":faltas_4b", $data['faltas_4b'], PDO::PARAM_INT);
        $stmt->bindParam(":media_final", $data['media_final'], PDO::PARAM_STR);
        $stmt->bindParam(":frequencia_final", $data['frequencia_final'], PDO::PARAM_STR);
        $stmt->bindParam(":recuperacao_final", $data['recuperacao_final'], PDO::PARAM_STR);
        $stmt->bindParam(":resultado_final", $data['resultado_final']);
        $stmt->bindParam(":observacoes", $data['observacoes']);
    }

    /**
     * Deleta um registro de boletim.
     * @param int $idBoletim
     * @param int $id_escola
     * @return bool
     */
    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function delete($idBoletim, $id_escola)
    {
        $query = "DELETE FROM " . $this->table_name . " WHERE id_boletim = :id_boletim AND id_escola = :id_escola"; // <-- ALTERADO
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_boletim", $idBoletim, PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO
        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao deletar entrada de boletim: " . implode(" ", $stmt->errorInfo()));
        return false;
    }
}