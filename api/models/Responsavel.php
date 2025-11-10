<?php
// api/models/Responsavel.php (VERSÃO COMPLETA E CORRIGIDA)

require_once __DIR__ . '/../../config/Database.php';

class Responsavel
{
    private $conn;
    private $table_name = "responsaveis";

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    private function cleanNumber($number)
    {
        return $number ? preg_replace('/\D/', '', $number) : null;
    }

    public function findOrCreate($data)
    {
        $cpf_clean = $this->cleanNumber($data['cpf']);

        // Para a funcionalidade de "Serasa", a busca inicial deve ser global
        // Para a criação de alunos, a busca deve ser por escola
        $id_escola_busca = $data['id_escola'] ?? null;

        $existingResponsavel = $this->findByCpf($cpf_clean, $id_escola_busca);

        if ($existingResponsavel) {
            return (int) $existingResponsavel['id_responsavel'];
        }

        $newData = [
            'nome' => $data['nome'],
            'cpf' => $cpf_clean,
            'data_nascimento' => $data['data_nascimento'] ?? null,
            'cep' => $data['cep'] ?? null,
            'email' => $data['email'] ?? null,
            'celular' => isset($data['celular']) ? $this->cleanNumber($data['celular']) : null,
            'telefone' => isset($data['telefone']) ? $this->cleanNumber($data['telefone']) : null,
            'id_escola' => $data['id_escola']
        ];

        $newId = $this->create($newData);

        return $newId ? (int) $newId : false;
    }

    /**
     * Busca um responsável pelo CPF.
     * Se id_escola for fornecido, a busca é restrita àquela escola.
     * Se id_escola for nulo, a busca é feita em toda a tabela (busca global).
     */
    public function findByCpf($cpf, $id_escola = null)
    {
        $query = "SELECT * FROM " . $this->table_name . " WHERE cpf = :cpf";

        if ($id_escola !== null) {
            $query .= " AND id_escola = :id_escola";
        }

        $query .= " LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':cpf', $cpf);

        if ($id_escola !== null) {
            $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data)
    {
       $query = "INSERT INTO " . $this->table_name . " (nome, cpf, data_nascimento, cep, email, celular, telefone, id_escola) VALUES (:nome, :cpf, :data_nascimento, :cep, :email, :celular, :telefone, :id_escola)";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":nome", $data['nome']);
        $stmt->bindParam(":cpf", $data['cpf']);
        $stmt->bindParam(":data_nascimento", $data['data_nascimento']);
        $stmt->bindParam(":cep", $data['cep']);
        $stmt->bindParam(":email", $data['email']);
        $stmt->bindParam(":celular", $data['celular']);
        $stmt->bindParam(":telefone", $data['telefone']);
        $stmt->bindParam(":id_escola", $data['id_escola'], PDO::PARAM_INT);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        error_log("Erro ao criar responsável: " . implode(" ", $stmt->errorInfo()));
        return false;
    }


}