<?php
// api/models/Financeiro.php

require_once __DIR__ . '/../../config/Database.php';

class Financeiro
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    // --- MÉTODOS PARA TIPOS DE CUSTO (SAÍDAS) ---

    // <-- ALTERADO: Adicionados parâmetros de autenticação
    public function findAllTiposCusto($id_escola, $userRole)
    {
        $query = "SELECT id, nome FROM tipos_custo";
        $params = [];

        // <-- ALTERADO: Filtro por escola
        if ($userRole !== 'superadmin') {
            $query .= " WHERE id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        $query .= " ORDER BY nome ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function createTipoCusto($nome, $id_escola)
    {
        try {
            // <-- ALTERADO: Adicionado campo id_escola
            $query = "INSERT INTO tipos_custo (nome, id_escola) VALUES (:nome, :id_escola)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':nome', $nome);
            $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT); // <-- ALTERADO
            $stmt->execute();
            $lastId = $this->conn->lastInsertId();
            return ['success' => true, 'data' => ['id' => $lastId, 'nome' => $nome]];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Erro de banco de dados: ' . $e->getMessage()];
        }
    }

    // --- MÉTODOS PARA TIPOS DE RECEITA (ENTRADAS) ---

    // <-- ALTERADO: Adicionados parâmetros de autenticação
    public function findAllTiposReceita($id_escola, $userRole)
    {
        $query = "SELECT id, nome FROM tipos_receita";
        $params = [];

        // <-- ALTERADO: Filtro por escola
        if ($userRole !== 'superadmin') {
            $query .= " WHERE id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        $query .= " ORDER BY nome ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function createTipoReceita($nome, $id_escola)
    {
        try {
            // <-- ALTERADO: Adicionado campo id_escola
            $query = "INSERT INTO tipos_receita (nome, id_escola) VALUES (:nome, :id_escola)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':nome', $nome);
            $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT); // <-- ALTERADO
            $stmt->execute();
            $lastId = $this->conn->lastInsertId();
            return ['success' => true, 'data' => ['id' => $lastId, 'nome' => $nome]];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Erro de banco de dados: ' . $e->getMessage()];
        }
    }

    // --- MÉTODOS DE TRANSAÇÕES ATUALIZADOS ---

    // <-- ALTERADO: Adicionados parâmetros de autenticação
    public function findAllTransacoes($id_escola, $userRole)
    {
        $query = "
            SELECT 
                t.id, t.descricao, t.valor, t.data, t.tipo, 
                t.id_tipo_custo, tc.nome as tipo_custo_nome,
                t.id_tipo_receita, tr.nome as tipo_receita_nome
            FROM transacoes as t
            LEFT JOIN tipos_custo as tc ON t.id_tipo_custo = tc.id
            LEFT JOIN tipos_receita as tr ON t.id_tipo_receita = tr.id
        ";
        $params = [];

        // <-- ALTERADO: Filtro por escola
        if ($userRole !== 'superadmin') {
            $query .= " WHERE t.id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        $query .= " ORDER BY t.data DESC, t.id DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function createTransacao($data, $id_escola)
    {
        // <-- ALTERADO: Adicionado campo id_escola
        $query = "INSERT INTO transacoes (descricao, valor, data, tipo, id_tipo_custo, id_tipo_receita, id_escola) 
                  VALUES (:descricao, :valor, :data, :tipo, :id_tipo_custo, :id_tipo_receita, :id_escola)";
        try {
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':descricao', $data->descricao);
            $stmt->bindParam(':valor', $data->valor);
            $stmt->bindParam(':data', $data->data);
            $stmt->bindParam(':tipo', $data->tipo);
            $stmt->bindParam(':id_tipo_custo', $data->id_tipo_custo, PDO::PARAM_INT);
            $stmt->bindParam(':id_tipo_receita', $data->id_tipo_receita, PDO::PARAM_INT);
            $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT); // <-- ALTERADO
            $stmt->execute();

            $lastId = $this->conn->lastInsertId();
            $newTransaction = $this->findTransacaoById($lastId, $id_escola, 'admin'); // <-- ALTERADO

            return ['success' => true, 'data' => $newTransaction];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Erro ao salvar a transação: ' . $e->getMessage()];
        }
    }

    // <-- ALTERADO: Adicionados parâmetros de autenticação
    private function findTransacaoById($id, $id_escola, $userRole)
    {
        $query = "
            SELECT t.*, tc.nome as tipo_custo_nome, tr.nome as tipo_receita_nome
            FROM transacoes t 
            LEFT JOIN tipos_custo tc ON t.id_tipo_custo = tc.id 
            LEFT JOIN tipos_receita tr ON t.id_tipo_receita = tr.id
            WHERE t.id = :id";

        $params = [':id' => $id];

        // <-- ALTERADO: Filtro por escola
        if ($userRole !== 'superadmin') {
            $query .= " AND t.id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // <-- ALTERADO: Adicionados parâmetros de autenticação
    public function findAllApprovedMensalidades($id_escola, $userRole)
    {
        $query = "
            SELECT 
                m.id_mensalidade,
                CONCAT(m.descricao, ' - ', a.nome_aluno) AS descricao, 
                m.valor_pago AS valor,
                m.data_pagamento AS data,
                'entrada' AS tipo,
                NULL AS id_tipo_custo,
                'Mensalidade Aprovada' AS tipo_custo_nome,
                NULL AS id_tipo_receita, 
                NULL as tipo_receita_nome
            FROM 
                mensalidades m
            JOIN 
                alunos a ON m.id_aluno = a.id_aluno
            WHERE 
                m.status = 'approved' AND m.valor_pago > 0 AND m.data_pagamento IS NOT NULL
        ";
        $params = [];

        // <-- ALTERADO: Filtro por escola
        if ($userRole !== 'superadmin') {
            // A tabela mensalidades precisa ter a coluna id_escola
            $query .= " AND m.id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteTransacoesByIds(array $ids, $id_escola)
    {
        if (empty($ids)) {
            return ['success' => true, 'data' => ['deleted_count' => 0]];
        }

        // Cria os placeholders (?) para a cláusula IN, evitando SQL Injection
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Adiciona o filtro de id_escola para garantir que um usuário
        // não apague transações de outra escola.
        $query = "DELETE FROM transacoes WHERE id IN ($placeholders) AND id_escola = ?";

        try {
            $stmt = $this->conn->prepare($query);

            // Associa os IDs aos placeholders
            foreach ($ids as $k => $id) {
                $stmt->bindValue(($k + 1), $id, PDO::PARAM_INT);
            }
            // Associa o id_escola ao último placeholder
            $stmt->bindValue(count($ids) + 1, $id_escola, PDO::PARAM_INT);

            $stmt->execute();

            // Retorna o número de linhas afetadas (deletadas)
            $deletedCount = $stmt->rowCount();

            return ['success' => true, 'data' => ['deleted_count' => $deletedCount]];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Erro ao deletar transações: ' . $e->getMessage()];
        }
    }

    
}