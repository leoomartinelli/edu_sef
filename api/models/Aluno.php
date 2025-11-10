<?php
// api/models/Aluno.php
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../models/Usuario.php';

class Aluno
{
    private $conn;
    private $table_name = "alunos";
    private $turmas_table_name = "turmas";
    private $responsaveis_table_name = "responsaveis";
    private $usuarioModel;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->usuarioModel = new Usuario();
    }

    private function cleanNumber($number)
    {
        return $number ? preg_replace('/\D/', '', $number) : null;
    }

    // MODIFICADO: Renomeado de existsByRa para existsByRaSef
    private function existsByRaSef($ra_sef, $excludeId = null)
    {
        // MODIFICADO: Removido "AND id_escola = :id_escola"
        $query = "SELECT id_aluno FROM " . $this->table_name . " WHERE ra_sef = :ra_sef";
        if ($excludeId !== null) {
            $query .= " AND id_aluno != :exclude_id";
        }
        $query .= " LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":ra_sef", $ra_sef);
        if ($excludeId !== null) {
            $stmt->bindParam(":exclude_id", $excludeId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public function getAll($id_escola, $userRole, $searchName = '', $searchRa = '', $searchTurmaName = '')
    {
        $query = "SELECT a.*, t.nome_turma, r.nome as nome_resp_financeiro
                   FROM " . $this->table_name . " a
                    LEFT JOIN " . $this->turmas_table_name . " t ON a.id_turma = t.id_turma
                    LEFT JOIN " . $this->responsaveis_table_name . " r ON a.id_resp_financeiro = r.id_responsavel";

        $conditions = [];
        $params = [];

        if ($userRole !== 'superadmin') {
            $conditions[] = "a.id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        if (!empty($searchName)) {
            $conditions[] = "a.nome_aluno LIKE :searchName";
            $params[':searchName'] = '%' . $searchName . '%';
        }

        if (!empty($searchRa)) {
            $conditions[] = "a.ra_sef = :searchRa"; // MODIFICADO: de a.ra para a.ra_sef
            $params[':searchRa'] = $searchRa;
        }

        if (!empty($searchTurmaName)) {
            $conditions[] = "t.nome_turma LIKE :searchTurmaName";
            $params[':searchTurmaName'] = '%' . $searchTurmaName . '%';
        }

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }

        $query .= " ORDER BY a.nome_aluno ASC";

        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => &$val) {
            $stmt->bindParam($key, $val);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id, $id_escola, $userRole)
    {
        $query = "SELECT
                    a.id_aluno, a.nome_aluno, a.ra_sef, a.ra, a.data_nascimento, a.rg_aluno, a.cpf_aluno, -- MODIFICADO: Seleciona ra_sef e ra
                    a.idade, a.id_turma, a.periodo, a.endereco, a.complemento, a.bairro,
                    a.cidade, a.cep, a.estado, a.ano_inicio, 
                    
                    r.nome as nome_resp_financeiro,
                    r.cpf as cpf_resp_financeiro,
                    r.data_nascimento as data_nascimento_resp_financeiro,
                    r.celular as celular_resp_financeiro,  
                    r.telefone as telefone_resp_financeiro, 
                    r.email as email_resp_financeiro,      
                    
                    a.nome_resp_pedagogico, a.cpf_resp_pedagogico, a.data_nascimento_resp_pedagogico, a.celular_resp_pedagogico,
                    a.telefone_resp_pedagogico, a.email_resp_pedagogico,
                    
                    t.nome_turma
                  FROM " . $this->table_name . " a
                  LEFT JOIN " . $this->turmas_table_name . " t ON a.id_turma = t.id_turma
                  LEFT JOIN " . $this->responsaveis_table_name . " r ON a.id_resp_financeiro = r.id_responsavel
                  WHERE a.id_aluno = :id";

        if ($userRole !== 'superadmin') {
            $query .= " AND a.id_escola = :id_escola";
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

    public function getByUserId($idUsuario)
    {
        // a.* vai selecionar ra_sef e ra, o que está correto.
        $query = "SELECT a.*, t.nome_turma 
                  FROM " . $this->table_name . " a
                  JOIN usuarios u ON a.id_aluno = u.id_aluno
                  LEFT JOIN turmas t ON a.id_turma = t.id_turma
                  WHERE u.id_usuario = :id_usuario 
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_usuario', $idUsuario, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data, $id_escola)
    {
        $generated_ra_sef = null;
        $lastId = null; // Guarda o ID do aluno para um possível rollback manual

        try {
            // 1. GERAR OU VALIDAR O RA_SEF
            if (!empty($data['ra_sef'])) { // Veio da planilha
                // MODIFICADO: Chamada agora é global, sem id_escola
                if ($this->existsByRaSef($data['ra_sef'])) {
                    throw new Exception("O RA SEF '{$data['ra_sef']}' (da planilha) já existe em outra escola.");
                }
                $generated_ra_sef = $data['ra_sef'];

            } else { // Veio do formulário
                // Validação do Ano de Início
                if (empty($data['ano_inicio']) || !is_numeric($data['ano_inicio']) || strlen((string) $data['ano_inicio']) != 4) {
                    throw new Exception("O campo 'Ano de Início' é obrigatório e deve ser um ano válido (ex: 2025).");
                }
                $anoRa = (string) $data['ano_inicio']; // <-- USA O NOVO CAMPO AQUI

                // =================================================================
                // ===               INÍCIO DA CORREÇÃO (GLOBAL)                 ===
                // =================================================================
                // MODIFICADO: Query agora é GLOBAL (removemos o WHERE id_escola)
                $countQuery = "SELECT COUNT(id_aluno) as total FROM " . $this->table_name . " FOR UPDATE";
                $stmtCount = $this->conn->prepare($countQuery);
                // Não precisa mais de bindParam para id_escola
                $stmtCount->execute();
                $row = $stmtCount->fetch(PDO::FETCH_ASSOC);
                // =================================================================
                // ===                FIM DA CORREÇÃO (GLOBAL)                   ===
                // =================================================================

                $nextCount = $row ? (int) $row['total'] + 1 : 1;
                $generated_ra_sef = str_pad($nextCount, 4, '0', STR_PAD_LEFT) . $anoRa;

                $maxTries = 5;
                // MODIFICADO: Chamada agora é global
                while ($this->existsByRaSef($generated_ra_sef) && $maxTries > 0) {
                    $nextCount++;
                    $generated_ra_sef = str_pad($nextCount, 4, '0', STR_PAD_LEFT) . $anoRa;
                    $maxTries--;
                }
                if ($maxTries <= 0) {
                    throw new Exception("Falha ao gerar um RA SEF único após 5 tentativas.");
                }
            }

            // 2. Query de Inserção (Aluno)
            $query = "INSERT INTO " . $this->table_name . " (
                nome_aluno, ra_sef, data_nascimento, cep, id_turma, periodo,
                nome_resp_pedagogico, telefone_resp_pedagogico, data_nascimento_resp_pedagogico, email_resp_pedagogico,
                id_resp_financeiro, id_escola, rg_aluno, cpf_aluno, cpf_resp_pedagogico, celular_resp_pedagogico,
                endereco, bairro, cidade, estado,
                idade, complemento, ra, data_inicio, ano_inicio
            ) VALUES (
                :nome_aluno, :ra_sef, :data_nascimento, :cep, :id_turma, :periodo,
                :nome_resp_pedagogico, :telefone_resp_pedagogico, :data_nascimento_resp_pedagogico, :email_resp_pedagogico,
                :id_resp_financeiro, :id_escola, :rg_aluno, :cpf_aluno, :cpf_resp_pedagogico, :celular_resp_pedagogico,
                :endereco, :bairro, :cidade, :estado,
                :idade, :complemento, :ra, :data_inicio, :ano_inicio
            )";
            $stmt = $this->conn->prepare($query);

            // ... (Binds) ...
            $id_turma = !empty($data['id_turma']) ? (int) $data['id_turma'] : null;
            $idade = !empty($data['idade']) ? (int) $data['idade'] : null;
            $cpf_aluno_clean = $this->cleanNumber($data['cpf_aluno'] ?? null);
            $cpf_resp_pedagogico_clean = $this->cleanNumber($data['cpf_resp_pedagogico'] ?? null);
            $telefone_resp_pedagogico_clean = $this->cleanNumber($data['telefone_resp_pedagogico'] ?? null);
            $celular_resp_pedagogico_clean = $this->cleanNumber($data['celular_resp_pedagogico'] ?? null);
            $ra_novo = $data['ra'] ?? null;

            $stmt->bindParam(":nome_aluno", $data['nome_aluno']);
            $stmt->bindParam(":ra_sef", $generated_ra_sef);
            $stmt->bindParam(":ra", $ra_novo);
            $stmt->bindParam(":data_nascimento", $data['data_nascimento']);
            $stmt->bindParam(":rg_aluno", $data['rg_aluno']);
            $stmt->bindParam(":cpf_aluno", $cpf_aluno_clean);
            $stmt->bindParam(":idade", $idade, PDO::PARAM_INT);
            $stmt->bindParam(":id_turma", $id_turma, PDO::PARAM_INT);
            $stmt->bindParam(":periodo", $data['periodo']);
            $stmt->bindParam(":endereco", $data['endereco']);
            $stmt->bindParam(":complemento", $data['complemento']);
            $stmt->bindParam(":bairro", $data['bairro']);
            $stmt->bindParam(":cidade", $data['cidade']);
            $stmt->bindParam(":cep", $data['cep']);
            $stmt->bindParam(":estado", $data['estado']);
            $stmt->bindParam(":nome_resp_pedagogico", $data['nome_resp_pedagogico']);
            $stmt->bindParam(":cpf_resp_pedagogico", $cpf_resp_pedagogico_clean);
            $stmt->bindParam(":data_nascimento_resp_pedagogico", $data['data_nascimento_resp_pedagogico']);
            $stmt->bindParam(":telefone_resp_pedagogico", $telefone_resp_pedagogico_clean);
            $stmt->bindParam(":celular_resp_pedagogico", $celular_resp_pedagogico_clean);
            $stmt->bindParam(":email_resp_pedagogico", $data['email_resp_pedagogico']);
            $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT);
            $stmt->bindParam(":id_resp_financeiro", $data['id_resp_financeiro'], PDO::PARAM_INT);
            $stmt->bindParam(":data_inicio", $data['data_inicio']);
            $stmt->bindParam(":ano_inicio", $data['ano_inicio'], PDO::PARAM_INT);


            // 3. Tenta criar o ALUNO
            if (!$stmt->execute()) {
                throw new Exception("Falha ao inserir o aluno na tabela.");
            }
            $lastId = $this->conn->lastInsertId();

            // 4. Verifica a Data de Nascimento (obrigatório para login)
            if (empty($data['data_nascimento'])) {
                throw new Exception("Data de nascimento é obrigatória para criar o usuário de login.");
            }

            // 5. Tenta formatar a Data de Nascimento (para senha)
            try {
                $dateObj = new DateTime($data['data_nascimento']);
                $alunoPassword = $dateObj->format('dmY');
            } catch (Exception $dateError) {
                throw new Exception("Formato de data de nascimento inválido.");
            }

            // 6. Tenta criar o USUÁRIO
            $userCreated = $this->usuarioModel->create([
                'username' => $generated_ra_sef,
                'password' => $alunoPassword,
                'role' => 'aluno_pendente',
                'id_aluno' => $lastId,
                'id_escola' => $id_escola
            ]);

            // 7. Verifica se o usuário foi criado
            if (!$userCreated) {
                throw new Exception("Falha ao criar o usuário de login. O RA SEF (username) '{$generated_ra_sef}' já pode estar em uso.");
            }

            // 8. Se tudo deu certo, retorna os dados
            return ['id_aluno' => $lastId, 'ra_sef' => $generated_ra_sef];

        } catch (Exception $e) {

            // "Rollback" Manual
            if ($lastId) {
                try {
                    $delStmt = $this->conn->prepare("DELETE FROM " . $this->table_name . " WHERE id_aluno = :id");
                    $delStmt->bindParam(':id', $lastId, PDO::PARAM_INT);
                    $delStmt->execute();
                } catch (Exception $delE) {
                    error_log("Falha CRÍTICA no rollback manual: " . $delE->getMessage());
                }
            }

            error_log("Erro em Aluno->create: " . $e->getMessage());
            return $e->getMessage(); // Retorna a mensagem de erro
        }
    }


    public function update($data, $id_escola)
    {
        // O ra_sef (login do aluno) não deve ser alterado.

        $query = "UPDATE " . $this->table_name . " SET 
                    nome_aluno = :nome_aluno, 
                    ra = :ra, 
                    data_nascimento = :data_nascimento, 
                    rg_aluno = :rg_aluno, 
                    cpf_aluno = :cpf_aluno, 
                    idade = :idade, 
                    id_turma = :id_turma, 
                    periodo = :periodo, 
                    endereco = :endereco, 
                    complemento = :complemento, 
                    bairro = :bairro, 
                    cidade = :cidade, 
                    cep = :cep, 
                    estado = :estado,
                    data_inicio = :data_inicio,
                    ano_inicio = :ano_inicio, -- <-- COLUNA ADICIONADA
                    nome_resp_pedagogico = :nome_resp_pedagogico, 
                    cpf_resp_pedagogico = :cpf_resp_pedagogico, 
                    data_nascimento_resp_pedagogico = :data_nascimento_resp_pedagogico, 
                    celular_resp_pedagogico = :celular_resp_pedagogico, 
                    telefone_resp_pedagogico = :telefone_resp_pedagogico, 
                    email_resp_pedagogico = :email_resp_pedagogico,
                    id_resp_financeiro = :id_resp_financeiro
                  WHERE id_aluno = :id_aluno AND id_escola = :id_escola";

        $stmt = $this->conn->prepare($query);

        $id_turma = !empty($data['id_turma']) ? (int) $data['id_turma'] : null;
        $idade = !empty($data['idade']) ? (int) $data['idade'] : null;
        $cpf_aluno_clean = $this->cleanNumber($data['cpf_aluno']);
        $cpf_resp_pedagogico_clean = $this->cleanNumber($data['cpf_resp_pedagogico']);
        $celular_resp_pedagogico_clean = $this->cleanNumber($data['celular_resp_pedagogico']);
        $telefone_resp_pedagogico_clean = $this->cleanNumber($data['telefone_resp_pedagogico']);
        $ra_novo = $data['ra'] ?? null;
        $data_inicio_param = !empty($data['data_inicio']) ? $data['data_inicio'] : null;
        $ano_inicio_param = !empty($data['ano_inicio']) ? (int) $data['ano_inicio'] : null; // <-- VARIÁVEL ADICIONADA

        $stmt->bindParam(":id_aluno", $data['id_aluno'], PDO::PARAM_INT);
        $stmt->bindParam(":nome_aluno", $data['nome_aluno']);
        $stmt->bindParam(":ra", $ra_novo);
        $stmt->bindParam(":data_nascimento", $data['data_nascimento']);
        $stmt->bindParam(":rg_aluno", $data['rg_aluno']);
        $stmt->bindParam(":cpf_aluno", $cpf_aluno_clean);
        $stmt->bindParam(":idade", $idade, PDO::PARAM_INT);
        $stmt->bindParam(":id_turma", $id_turma, PDO::PARAM_INT);
        $stmt->bindParam(":periodo", $data['periodo']);
        $stmt->bindParam(":endereco", $data['endereco']);
        $stmt->bindParam(":complemento", $data['complemento']);
        $stmt->bindParam(":bairro", $data['bairro']);
        $stmt->bindParam(":cidade", $data['cidade']);
        $stmt->bindParam(":cep", $data['cep']);
        $stmt->bindParam(":estado", $data['estado']);
        $stmt->bindParam(":data_inicio", $data_inicio_param);
        $stmt->bindParam(":ano_inicio", $ano_inicio_param, PDO::PARAM_INT); // <-- BIND ADICIONADO
        $stmt->bindParam(":nome_resp_pedagogico", $data['nome_resp_pedagogico']);
        $stmt->bindParam(":cpf_resp_pedagogico", $cpf_resp_pedagogico_clean);
        $stmt->bindParam(":data_nascimento_resp_pedagogico", $data['data_nascimento_resp_pedagogico']);
        $stmt->bindParam(":celular_resp_pedagogico", $celular_resp_pedagogico_clean);
        $stmt->bindParam(":telefone_resp_pedagogico", $telefone_resp_pedagogico_clean);
        $stmt->bindParam(":email_resp_pedagogico", $data['email_resp_pedagogico']);
        $stmt->bindParam(":id_resp_financeiro", $data['id_resp_financeiro'], PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function delete($id, $id_escola)
    {
        // Esta função não precisa de mudanças, pois deleta pelo id_aluno
        $this->conn->beginTransaction();
        try {
            $queryMensalidades = "DELETE FROM mensalidades WHERE id_aluno = :id_aluno";
            $stmtMensalidades = $this->conn->prepare($queryMensalidades);
            $stmtMensalidades->bindParam(':id_aluno', $id);
            $stmtMensalidades->execute();

            $queryContratos = "DELETE FROM contratos WHERE id_aluno = :id_aluno";
            $stmtContratos = $this->conn->prepare($queryContratos);
            $stmtContratos->bindParam(':id_aluno', $id);
            $stmtContratos->execute();

            $queryAluno = "DELETE FROM " . $this->table_name . " WHERE id_aluno = :id_aluno AND id_escola = :id_escola";
            $stmtAluno = $this->conn->prepare($queryAluno);
            $stmtAluno->bindParam(':id_aluno', $id, PDO::PARAM_INT);
            $stmtAluno->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
            $stmtAluno->execute();

            if ($stmtAluno->rowCount() == 0) {
                throw new Exception("Tentativa de exclusão de aluno de outra escola ou aluno não encontrado.");
            }

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Erro ao deletar aluno: " . $e->getMessage());
            return false;
        }
    }

    public function getAlunosByTurmaId($idTurma, $id_escola)
    {
        // MODIFICADO: seleciona ra_sef
        $query = "SELECT a.id_aluno, a.nome_aluno, a.ra_sef, t.nome_turma FROM " . $this->table_name . " a
                  LEFT JOIN " . $this->turmas_table_name . " t ON a.id_turma = t.id_turma
                  WHERE a.id_turma = :id_turma AND a.id_escola = :id_escola ORDER BY a.nome_aluno ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_turma', $idTurma, PDO::PARAM_INT);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // MODIFICADO: Renomeado de findByRa para findByRaSef
    public function findByRaSef($ra_sef)
    {
        // MODIFICADO: Query usa ra_sef
        $query = "SELECT * FROM " . $this->table_name . " WHERE ra_sef = :ra_sef LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':ra_sef', $ra_sef);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getTotalCount($id_escola, $userRole)
    {
        // Esta função não precisa de mudanças
        $query = "SELECT COUNT(id_aluno) as total FROM " . $this->table_name;
        $params = [];
        if ($userRole !== 'superadmin') {
            $query .= " WHERE id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['total'] : 0;
    }
}
