<?php
// api/models/Usuario.php

require_once __DIR__ . '/../../config/Database.php';

class Usuario
{
    private $conn;
    private $table_name = "usuarios";

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    /**
     * Encontra um usuário pelo nome de usuário e retorna seus dados, incluindo status e id_escola.
     * @param string $username O nome de usuário.
     * @return array|false Os dados do usuário ou false se não encontrado.
     */
    public function findByUsername($username)
    {
        // <-- ALTERADO: Adicionado 'email' na consulta
        $query = "SELECT id_usuario, username, password_hash, role, email, status, id_escola FROM " . $this->table_name . " WHERE username = :username LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByEmail($email)
    {
        $query = "SELECT * FROM " . $this->table_name . " WHERE email = :email LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Verifica se a senha fornecida corresponde ao hash.
     * @param string $password A senha em texto puro.
     * @param string $hashedPassword O hash da senha armazenado no banco.
     * @return bool True se a senha for válida, false caso contrário.
     */
    public function verifyPassword($password, $hashedPassword)
    {
        return password_verify($password, $hashedPassword);
    }

    /**
     * Atualiza a senha de um usuário e define seu status como 'ativo'.
     * @param int    $idUsuario   ID do usuário.
     * @param string $newPassword Nova senha em texto puro.
     * @return bool Retorna true em caso de sucesso, false em caso de falha.
     */
    public function updatePasswordAndStatus($idUsuario, $newPassword, $addemail)
    {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        $query = "UPDATE " . $this->table_name . " 
              SET password_hash = :password_hash, 
                  email = :email, 
                  status = 'ativo' 
              WHERE id_usuario = :id";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(':password_hash', $hashedPassword);
        $stmt->bindParam(':email', $addemail);
        $stmt->bindParam(':id', $idUsuario, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Cria um novo usuário no banco de dados.
     * @param array $data Dados do usuário (username, password, role, id_escola, email). <-- ALTERADO
     * @return bool True em caso de sucesso, false em caso de falha.
     */
    public function create($data)
    {
        if ($this->findByUsername($data['username'])) {
            error_log("Tentativa de criar usuário com username existente: " . $data['username']);
            return false;
        }

        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

        // <-- ALTERADO: Adicionado 'email' no INSERT
        $query = "INSERT INTO " . $this->table_name . " (username, password_hash, role, email, id_aluno, id_escola) VALUES (:username, :password_hash, :role, :email, :id_aluno, :id_escola)";

        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":username", $data['username']);
        $stmt->bindParam(":password_hash", $hashedPassword);
        $stmt->bindParam(":role", $data['role']);

        // <-- ALTERADO: Bind do email (pode ser null)
        $email_param = isset($data['email']) ? $data['email'] : null;
        $stmt->bindParam(":email", $email_param);

        $id_aluno_param = isset($data['id_aluno']) ? $data['id_aluno'] : null;
        $stmt->bindParam(":id_aluno", $id_aluno_param, PDO::PARAM_INT);

        $id_escola_param = isset($data['id_escola']) ? $data['id_escola'] : null;
        $stmt->bindParam(":id_escola", $id_escola_param, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Deleta um usuário pelo nome de usuário.
     * @param string $username O nome de usuário a ser deletado.
     * @return bool True em caso de sucesso, false em caso de falha.
     */
    public function deleteByUsername($username)
    {
        $query = "DELETE FROM " . $this->table_name . " WHERE username = :username";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);
        return $stmt->execute();
    }

    /**
     * Atualiza a senha de um usuário identificado pelo ID (Usado pelo Admin).
     * @param int    $idUsuario   ID do usuário.
     * @param string $newPassword Nova senha.
     * @return bool Retorna true em caso de sucesso, false em caso de falha.
     */
    public function updatePassword($idUsuario, $newPassword)
    {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $query = "UPDATE " . $this->table_name . " SET password_hash = :password_hash, status = 'pendente_senha' WHERE id_usuario = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':password_hash', $hashedPassword);
        $stmt->bindParam(':id', $idUsuario, PDO::PARAM_INT);
        return $stmt->execute();
    }

    /**
     * Libera o acesso de um aluno alterando sua role para 'aluno'.
     * @param int $idAluno O ID do aluno.
     * @return bool True se a role foi atualizada com sucesso, false caso contrário.
     */
    public function liberarAcessoAluno($idAluno)
    {
        $query = "UPDATE " . $this->table_name . " SET role = 'aluno' WHERE id_aluno = :id_aluno AND role = 'aluno_pendente'";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_aluno', $idAluno, PDO::PARAM_INT);
        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    // <-- ALTERADO: Recebe id_escola e userRole para filtrar a listagem
    public function getAllUsers($id_escola, $userRole, $roleFilter = null, $nomeAluno = null, $ra = null)
    {
        // <-- ALTERADO: Adicionado u.email
        $query = "SELECT u.id_usuario, u.username, u.role, u.email, a.nome_aluno 
              FROM " . $this->table_name . " u 
              LEFT JOIN alunos a ON u.id_aluno = a.id_aluno";

        $conditions = [];
        $params = [];

        if ($userRole !== 'superadmin') {
            $conditions[] = "u.id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        if ($roleFilter) {
            $conditions[] = "u.role = :roleFilter";
            $params[':roleFilter'] = $roleFilter;
        }
        if ($nomeAluno) {
            $conditions[] = "a.nome_aluno LIKE :nome_aluno";
            $params[':nome_aluno'] = '%' . $nomeAluno . '%';
        }
        if ($ra) {
            $conditions[] = "a.ra = :ra";
            $params[':ra'] = $ra;
        }

        if (count($conditions) > 0) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }

        $query .= " ORDER BY a.nome_aluno ASC, u.username ASC";
        $stmt = $this->conn->prepare($query);
        foreach ($params as $key => &$val) {
            $stmt->bindParam($key, $val);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById($id)
    {
        // <-- ALTERADO: Adicionado email
        $query = "SELECT id_usuario, username, role, email, id_escola FROM " . $this->table_name . " WHERE id_usuario = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateUser($id, $data, $id_escola)
    {
        // <-- ALTERADO: Adicionado email no UPDATE
        $query = "UPDATE " . $this->table_name . " SET username = :username, role = :role, email = :email WHERE id_usuario = :id AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':username', $data['username']);
        $stmt->bindParam(':role', $data['role']);

        // <-- ALTERADO: Bind do email
        $email_param = isset($data['email']) ? $data['email'] : null;
        $stmt->bindParam(':email', $email_param);

        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function deleteById($id, $id_escola)
    {
        if ($id == 1) {
            return false;
        }
        $query = "DELETE FROM " . $this->table_name . " WHERE id_usuario = :id AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function setResetToken($idUsuario)
    {
        // Gera um código numérico de 6 dígitos
        $token = strval(random_int(100000, 999999));

        // Define a expiração para 10 minutos a partir de agora
        $expires = date('Y-m-d H:i:s', time() + (10 * 60));

        $query = "UPDATE " . $this->table_name . " 
                  SET reset_token = :token, 
                      reset_token_expires = :expires 
                  WHERE id_usuario = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->bindParam(':expires', $expires);
        $stmt->bindParam(':id', $idUsuario, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return $token; // Retorna o código para ser enviado por e-mail
        }
        return false;
    }

    /**
     * Reseta a senha do usuário se o token for válido e não expirado.
     * @param string $token O token recebido por e-mail.
     * @param string $newPassword A nova senha.
     * @return bool True se a senha foi alterada, false caso contrário.
     */
    public function resetPasswordByToken($token, $newPassword)
    {
        // 1. Encontrar o usuário com o token válido e não expirado
        $query = "SELECT id_usuario FROM " . $this->table_name . " 
                  WHERE reset_token = :token AND reset_token_expires > NOW() 
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return false; // Token inválido ou expirado
        }

        $idUsuario = $user['id_usuario'];
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        // 2. Atualizar a senha e limpar o token
        $queryUpdate = "UPDATE " . $this->table_name . " 
                        SET password_hash = :password_hash,
                            status = 'ativo',
                            reset_token = NULL,
                            reset_token_expires = NULL
                        WHERE id_usuario = :id";

        $stmtUpdate = $this->conn->prepare($queryUpdate);
        $stmtUpdate->bindParam(':password_hash', $hashedPassword);
        $stmtUpdate->bindParam(':id', $idUsuario, PDO::PARAM_INT);

        return $stmtUpdate->execute();
    }
}