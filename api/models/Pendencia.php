<?php
// api/models/Pendencia.php

require_once __DIR__ . '/../../config/Database.php';

class Pendencia
{
    private $conn;
    private $table_name = "pendencias";

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    // Dentro do arquivo api/models/Pendencia.php

    public function findPendenciasByResponsavelId($id_responsavel, $id_escola = null)
    {
        // CORREÇÃO FINAL: Usando 'e.nome_escola' que é o nome correto da sua coluna
        $query = "SELECT p.*, e.nome_escola as nome_escola_credora 
              FROM " . $this->table_name . " p
              LEFT JOIN escolas e ON p.id_escola = e.id_escola
              WHERE p.id_responsavel = :id_responsavel";

        // Adiciona o filtro de escola APENAS se o id_escola for fornecido
        if ($id_escola !== null) {
            $query .= " AND p.id_escola = :id_escola";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_responsavel', $id_responsavel, PDO::PARAM_INT);

        if ($id_escola !== null) {
            $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Nenhuma alteração necessária aqui, pois já recebe id_escola nos dados
    public function create($data)
    {
        // A verificação de duplicata agora também checa o id_escola
        $checkQuery = "SELECT COUNT(*) FROM " . $this->table_name . " WHERE id_responsavel = :id_responsavel AND credor = :credor AND valor = :valor AND data_ocorrencia = :data_ocorrencia AND id_escola = :id_escola";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(":id_responsavel", $data['id_responsavel']);
        $checkStmt->bindParam(":credor", $data['creditorName']);
        $checkStmt->bindParam(":valor", $data['amount']);
        $checkStmt->bindParam(":data_ocorrencia", $data['occurrenceDate']);
        $checkStmt->bindParam(":id_escola", $data['id_escola']);
        $checkStmt->execute();

        if ($checkStmt->fetchColumn() > 0) {
            return 'exists'; // Já existe
        }

        $query = "INSERT INTO " . $this->table_name . " (id_responsavel, id_escola, credor, valor, data_ocorrencia, cadus, natureza_juridica) VALUES (:id_responsavel, :id_escola, :credor, :valor, :data_ocorrencia, :cadus, :natureza_juridica)";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":id_responsavel", $data['id_responsavel']);
        $stmt->bindParam(":id_escola", $data['id_escola']);
        $stmt->bindParam(":credor", $data['creditorName']);
        $stmt->bindParam(":valor", $data['amount']);
        $stmt->bindParam(":data_ocorrencia", $data['occurrenceDate']);
        $stmt->bindParam(":cadus", $data['cadus']);
        $stmt->bindParam(":natureza_juridica", $data['legalNature']);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao criar pendência: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    
}