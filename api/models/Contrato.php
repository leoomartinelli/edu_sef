<?php
// api/models/Contrato.php

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/Auth.php';

class Contrato
{
    private $conn;
    private $table_name = "contratos";

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function create($data, $id_escola)
    {
        // <-- ALTERADO: Adicionado campo id_escola
        $query = "INSERT INTO " . $this->table_name . " (id_aluno, caminho_pdf, status, id_escola) VALUES (:id_aluno, :caminho_pdf, 'pendente', :id_escola)";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":id_aluno", $data['id_aluno']);
        $stmt->bindParam(":caminho_pdf", $data['caminho_pdf']);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT); // <-- ALTERADO

        return $stmt->execute();
    }

    public function findPendingByAlunoId($id_aluno)
    {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id_aluno = :id_aluno AND status = 'pendente' LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_aluno', $id_aluno, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // <-- ALTERADO: Adicionados parâmetros $id_escola e $userRole
    public function getContratoById($id, $id_escola, $userRole)
    {
        $query = "SELECT id_aluno, caminho_pdf, caminho_pdf_assinado FROM " . $this->table_name . " WHERE id_contrato = :id";

        // <-- ALTERADO: Filtro de segurança
        if ($userRole !== 'superadmin') {
            $query .= " AND id_escola = :id_escola";
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

    // <-- ALTERADO: Adicionados parâmetros $id_escola e $userRole
    public function getAllContratosComAlunos($id_escola, $userRole, $nomeAluno = null)
    {
        $query = "
            SELECT 
                c.id_contrato, c.caminho_pdf, c.caminho_pdf_assinado, c.assinado_validado, c.status,
                a.nome_aluno, a.id_aluno,
                ae.id_assinatura -- <-- NOVO: Verifica se existe uma assinatura eletrônica
            FROM " . $this->table_name . " c
            JOIN alunos a ON c.id_aluno = a.id_aluno
            LEFT JOIN assinaturas_eletronicas ae ON c.id_contrato = ae.id_contrato -- <-- NOVO: JOIN
        ";

        $conditions = [];
        $params = [];

        // <-- ALTERADO: Filtro principal por escola
        if ($userRole !== 'superadmin') {
            $conditions[] = "c.id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        if ($nomeAluno) {
            $conditions[] = "a.nome_aluno LIKE :nomeAluno";
            $params[':nomeAluno'] = "%" . $nomeAluno . "%";
        }

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }

        $query .= " ORDER BY c.assinado_validado ASC, a.nome_aluno ASC";
        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => &$val) {
            $stmt->bindParam($key, $val);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function marcarComoValidado($idContrato, $id_escola)
    {
        // <-- ALTERADO: Filtro de segurança com id_escola
        $query = "UPDATE " . $this->table_name . " SET assinado_validado = 1, status = 'validado' WHERE id_contrato = :id_contrato AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_contrato', $idContrato, PDO::PARAM_INT);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        return $stmt->execute();
    }


    // <-- ALTERADO: Adicionados parâmetros $id_escola e $userRole
    public function findById($id_contrato, $id_escola, $userRole)
    {
        // === INÍCIO DA MUDANÇA: Query agora busca dados do aluno e responsável ===
        $query = "SELECT 
                    c.*, 
                    a.id_aluno, 
                    a.nome_aluno, 
                    a.ra_sef,
                    a.ano_inicio,
                    r.nome as nome_resp_financeiro, 
                    r.celular as celular_resp_financeiro,
                    e.nome_escola,                     -- <-- ADICIONADO
                    e.telefone as numero_escola        -- <-- ADICIONADO (Assumindo 'telefone')
                  FROM " . $this->table_name . " c 
                  JOIN alunos a ON c.id_aluno = a.id_aluno
                  LEFT JOIN responsaveis r ON a.id_resp_financeiro = r.id_responsavel
                  LEFT JOIN escolas e ON c.id_escola = e.id_escola -- <-- ADICIONADO
                  WHERE c.id_contrato = :id_contrato";
        // === FIM DA MUDANÇA ===

        // <-- ALTERADO: Filtro de segurança
        if ($userRole !== 'superadmin') {
            $query .= " AND c.id_escola = :id_escola";
        }
        $query .= " LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_contrato', $id_contrato, PDO::PARAM_INT);
        if ($userRole !== 'superadmin') {
            $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getValidatedCountByYear($ano_letivo, $id_escola, $userRole)
    {
        // Precisamos buscar o ano_inicio na tabela 'alunos'
        $query = "SELECT COUNT(c.id_contrato) as total 
                  FROM " . $this->table_name . " c
                  JOIN alunos a ON c.id_aluno = a.id_aluno
                  WHERE c.assinado_validado = 1 
                  AND a.ano_inicio = :ano_letivo";

        $params = [':ano_letivo' => $ano_letivo];

        if ($userRole !== 'superadmin') {
            $query .= " AND c.id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Retorna a contagem (ex: 50)
        return $row ? (int) $row['total'] : 0;
    }

    public function updateAssinatura($idContrato, $caminhoAssinado)
    {
        // Esta operação é feita por um aluno, então a validação de escola é implícita (aluno só vê o próprio contrato).
        $query = "UPDATE " . $this->table_name . " SET caminho_pdf_assinado = :caminho, status = 'em_analise', data_assinatura = NOW() WHERE id_contrato = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':caminho', $caminhoAssinado);
        $stmt->bindParam(':id', $idContrato, PDO::PARAM_INT);
        return $stmt->execute();
    }

    // <-- ALTERADO: Adicionados parâmetros $id_escola e $userRole
    public function getPendingCount($id_escola, $userRole)
    {
        $query = "SELECT COUNT(id_contrato) as total FROM " . $this->table_name . " WHERE assinado_validado = 0";
        $params = [];

        if ($userRole !== 'superadmin') {
            $query .= " AND id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['total'] : 0;
    }

    // <-- ALTERADO: Adicionados parâmetros $id_escola e $userRole
    public function getValidatedCount($id_escola, $userRole)
    {
        $query = "SELECT COUNT(id_contrato) as total FROM " . $this->table_name . " WHERE assinado_validado = 1";
        $params = [];

        if ($userRole !== 'superadmin') {
            $query .= " AND id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['total'] : 0;
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function delete($idContrato, $id_escola)
    {
        // Primeiro, busca o contrato de forma segura
        $contrato = $this->findById($idContrato, $id_escola, 'admin'); // Usamos 'admin' para forçar o filtro de escola
        if (!$contrato) {
            return false; // Contrato não encontrado ou não pertence a esta escola
        }

        try {
            $this->conn->beginTransaction();

            if (!empty($contrato['caminho_pdf']) && file_exists(__DIR__ . '/../../' . $contrato['caminho_pdf'])) {
                unlink(__DIR__ . '/../../' . $contrato['caminho_pdf']);
            }
            if (!empty($contrato['caminho_pdf_assinado']) && file_exists(__DIR__ . '/../../' . $contrato['caminho_pdf_assinado'])) {
                unlink(__DIR__ . '/../../' . $contrato['caminho_pdf_assinado']);
            }

            // <-- ALTERADO: Filtro de segurança no DELETE
            $query = "DELETE FROM " . $this->table_name . " WHERE id_contrato = :id_contrato AND id_escola = :id_escola";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id_contrato', $idContrato, PDO::PARAM_INT);
            $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);

            if (!$stmt->execute()) {
                throw new Exception("Falha ao deletar o registro do contrato.");
            }

            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Erro ao deletar contrato: " . $e->getMessage());
            return false;
        }
    }

    public function getContratoParaAssinatura($idContrato)
    {
        // ADICIONADO: "id_escola" ao SELECT
        $query = "SELECT caminho_pdf, id_aluno, id_escola FROM " . $this->table_name . " WHERE id_contrato = :id_contrato LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_contrato', $idContrato, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Salva a assinatura eletrônica no banco de dados
     * e atualiza o status do contrato.
     */
    public function salvarAssinaturaEletronica($data)
    {
        // 1. Inserir a assinatura eletrônica
        $queryAssinatura = "INSERT INTO assinaturas_eletronicas 
                                (id_contrato, id_aluno, assinatura_base64, hash_documento_sha256, ip_assinatura) 
                            VALUES 
                                (:id_contrato, :id_aluno, :assinatura_base64, :hash_documento_sha256, :ip_assinatura)";

        // 2. Atualizar o status do contrato E O CAMINHO DO PDF ASSINADO
        // <-- INÍCIO DA MODIFICAÇÃO -->
        $queryContrato = "UPDATE " . $this->table_name . " 
                          SET status = 'em_analise', 
                              data_assinatura = NOW(),
                              caminho_pdf_assinado = :caminho_pdf_assinado
                          WHERE id_contrato = :id_contrato";
        // <-- FIM DA MODIFICAÇÃO -->

        try {
            $this->conn->beginTransaction();

            // Executa Query 1
            $stmtAssinatura = $this->conn->prepare($queryAssinatura);
            $stmtAssinatura->bindParam(':id_contrato', $data['id_contrato'], PDO::PARAM_INT);
            $stmtAssinatura->bindParam(':id_aluno', $data['id_aluno'], PDO::PARAM_INT);
            $stmtAssinatura->bindParam(':assinatura_base64', $data['assinatura_base64']);
            $stmtAssinatura->bindParam(':hash_documento_sha256', $data['hash_documento_sha256']);
            $stmtAssinatura->bindParam(':ip_assinatura', $data['ip_assinatura']);
            $stmtAssinatura->execute();

            // Executa Query 2
            $stmtContrato = $this->conn->prepare($queryContrato);
            $stmtContrato->bindParam(':id_contrato', $data['id_contrato'], PDO::PARAM_INT);
            // <-- NOVA LINHA -->
            $stmtContrato->bindParam(':caminho_pdf_assinado', $data['caminho_pdf_assinado']);
            // <-- FIM DA NOVA LINHA -->
            $stmtContrato->execute();

            // Se tudo deu certo, comita a transação
            $this->conn->commit();
            return true;

        } catch (Exception $e) {
            // Se algo deu errado, reverte
            $this->conn->rollBack();
            error_log("Erro ao salvar assinatura eletrônica: " . $e->getMessage());
            return false;
        }
    }

    public function getAssinaturaEletronica($idContrato, $id_escola, $userRole)
    {
        // Junta com 'contratos' para garantir que o admin só vê assinaturas da sua escola
        $query = "SELECT ae.assinatura_base64, ae.hash_documento_sha256, ae.data_assinatura, ae.ip_assinatura
                  FROM assinaturas_eletronicas ae
                  JOIN contratos c ON ae.id_contrato = c.id_contrato
                  WHERE ae.id_contrato = :id_contrato";

        if ($userRole !== 'superadmin') {
            $query .= " AND c.id_escola = :id_escola";
        }
        $query .= " LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_contrato', $idContrato, PDO::PARAM_INT);
        if ($userRole !== 'superadmin') {
            $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}