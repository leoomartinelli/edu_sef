<?php
// api/models/MatriculaPendente.php
require_once __DIR__ . '/../../config/Database.php';

class MatriculaPendente
{
    private $conn;
    private $table_name = "matriculas_pendentes";

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    // Passo 2: Admin inicia o processo
    public function create($data)
    {
        $query = "INSERT INTO " . $this->table_name . " (
            id_escola, token, prazo, admin_resp_nome, admin_resp_cpf, admin_resp_celular,
            admin_ano_inicio, admin_id_turma, admin_anuidade, admin_matricula, admin_vencimento,
            admin_material, admin_parcelas_material, admin_bolsista
        ) VALUES (
            :id_escola, :token, :prazo, :admin_resp_nome, :admin_resp_cpf, :admin_resp_celular,
            :admin_ano_inicio, :admin_id_turma, :admin_anuidade, :admin_matricula, :admin_vencimento,
            :admin_material, :admin_parcelas_material, :admin_bolsista
        )";

        $stmt = $this->conn->prepare($query);

        // Bind dos parâmetros
        $stmt->bindParam(":id_escola", $data['id_escola'], PDO::PARAM_INT);
        $stmt->bindParam(":token", $data['token']);
        $stmt->bindParam(":prazo", $data['prazo']);
        $stmt->bindParam(":admin_resp_nome", $data['admin_resp_nome']);
        $stmt->bindParam(":admin_resp_cpf", $data['admin_resp_cpf']);
        $stmt->bindParam(":admin_resp_celular", $data['admin_resp_celular']);
        $stmt->bindParam(":admin_ano_inicio", $data['admin_ano_inicio'], PDO::PARAM_INT);
        $stmt->bindParam(":admin_id_turma", $data['admin_id_turma'], PDO::PARAM_INT);
        $stmt->bindParam(":admin_anuidade", $data['admin_anuidade']);
        $stmt->bindParam(":admin_matricula", $data['admin_matricula']);
        $stmt->bindParam(":admin_vencimento", $data['admin_vencimento'], PDO::PARAM_INT);
        $stmt->bindParam(":admin_material", $data['admin_material']);
        $stmt->bindParam(":admin_parcelas_material", $data['admin_parcelas_material'], PDO::PARAM_INT);
        $stmt->bindParam(":admin_bolsista", $data['admin_bolsista'], PDO::PARAM_BOOL);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        return false;
    }

    // Passo 3: Responsável preenche (usado pelo método público)
    public function findByToken($token)
    {
        $query = "SELECT * FROM " . $this->table_name . " WHERE token = :token AND status = 'Aguardando' LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":token", $token);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateFromForm($token, $data)
    {
        $query = "UPDATE " . $this->table_name . " SET
            status = 'Preenchido',
            resp_aluno_nome = :resp_aluno_nome,
            resp_aluno_nascimento = :resp_aluno_nascimento,
            resp_aluno_rg = :resp_aluno_rg,
            resp_aluno_cpf = :resp_aluno_cpf,
            resp_cep = :resp_cep,
            resp_endereco = :resp_endereco,
            resp_bairro = :resp_bairro,
            resp_cidade = :resp_cidade,
            resp_estado = :resp_estado,
            resp_complemento = :resp_complemento,
            resp_fin_email = :resp_fin_email,
            resp_fin_nascimento = :resp_fin_nascimento,
            resp_ped_nome = :resp_ped_nome,
            resp_ped_cpf = :resp_ped_cpf,
            resp_ped_celular = :resp_ped_celular,
            resp_ped_email = :resp_ped_email,
            resp_ped_nascimento = :resp_ped_nascimento
        WHERE token = :token";

        $stmt = $this->conn->prepare($query);
        
        // Bind dos parâmetros do responsável
        $stmt->bindParam(":token", $token);
        $stmt->bindParam(":resp_aluno_nome", $data['aluno_nome']);
        $stmt->bindParam(":resp_aluno_nascimento", $data['aluno_nascimento']);
        $stmt->bindParam(":resp_aluno_rg", $data['aluno_rg']);
        $stmt->bindParam(":resp_aluno_cpf", $data['aluno_cpf']);
        $stmt->bindParam(":resp_cep", $data['cep']);
        $stmt->bindParam(":resp_endereco", $data['endereco']);
        $stmt->bindParam(":resp_bairro", $data['bairro']);
        $stmt->bindParam(":resp_cidade", $data['cidade']);
        $stmt->bindParam(":resp_estado", $data['estado']);
        $stmt->bindParam(":resp_complemento", $data['complemento']);
        $stmt->bindParam(":resp_fin_email", $data['resp_fin_email']);
        $stmt->bindParam(":resp_fin_nascimento", $data['resp_fin_nascimento']);
        $stmt->bindParam(":resp_ped_nome", $data['resp_ped_nome']);
        $stmt->bindParam(":resp_ped_cpf", $data['resp_ped_cpf']);
        $stmt->bindParam(":resp_ped_celular", $data['resp_ped_celular']);
        $stmt->bindParam(":resp_ped_email", $data['resp_ped_email']);
        $stmt->bindParam(":resp_ped_nascimento", $data['resp_ped_nascimento']);

        return $stmt->execute();
    }

    // Passo 4: Admin lista os pendentes
    public function getAllByEscola($id_escola)
    {
        // Atualiza status de quem perdeu o prazo
        $this->updateStatusAtrasados($id_escola);

        $query = "SELECT id, admin_resp_nome, admin_resp_cpf, status, prazo 
                  FROM " . $this->table_name . " 
                  WHERE id_escola = :id_escola 
                  ORDER BY data_criacao DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Passo 5: Admin vê detalhes
    public function getById($id, $id_escola)
    {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id = :id AND id_escola = :id_escola LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id, PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Passo 5: Admin aceita (exclui o registro pendente)
    public function deleteById($id, $id_escola)
    {
        $query = "DELETE FROM " . $this->table_name . " WHERE id = :id AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $id, PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT);
        return $stmt->execute();
    }
    
    // Helper para atualizar status
    private function updateStatusAtrasados($id_escola)
    {
        $query = "UPDATE " . $this->table_name . " 
                  SET status = 'Fora do Prazo' 
                  WHERE id_escola = :id_escola 
                  AND status = 'Aguardando' 
                  AND prazo < CURDATE()";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT);
        $stmt->execute();
    }
}