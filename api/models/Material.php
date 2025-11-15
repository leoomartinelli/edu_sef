<?php
// api/models/Material.php

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../../config/Database.php';

class Material
{
    private $conn;
    private $table_name = "material_pedagogico"; // <-- ALTERADO

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function readAll($id_escola, $userRole, $searchTerm = null, $status = null, $yearMonth = null, $id_turma = null, $id_aluno = null)
    {
        $query = "SELECT 
            m.id_material, m.id_aluno, a.nome_aluno, r.nome as nome_resp_financeiro,
            m.valor_parcela, m.data_vencimento, m.data_pagamento,
            m.valor_pago, m.status, m.multa_aplicada, m.juros_aplicados, m.descricao
          FROM " . $this->table_name . " m
          JOIN alunos a ON m.id_aluno = a.id_aluno
          LEFT JOIN responsaveis r ON a.id_resp_financeiro = r.id_responsavel";

        $conditions = [];
        $params = [];

        if ($userRole !== 'superadmin') {
            $conditions[] = "m.id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        if ($id_turma) {
            $conditions[] = "a.id_turma = :id_turma";
            $params[':id_turma'] = $id_turma;
        }

        if ($id_aluno) {
            $conditions[] = "m.id_aluno = :id_aluno";
            $params[':id_aluno'] = $id_aluno;
        }

        if ($searchTerm) {
            $conditions[] = "(a.nome_aluno LIKE :searchTerm OR r.nome LIKE :searchTerm OR m.descricao LIKE :searchTerm)";
            $params[':searchTerm'] = "%" . $searchTerm . "%";
        }
        if ($status) {
            $conditions[] = "m.status = :status";
            $params[':status'] = $status;
        }
        if ($yearMonth && $yearMonth !== 'todos') {
            if ($status === 'approved') {
                $conditions[] = "DATE_FORMAT(m.data_pagamento, '%Y-%m') = :yearMonth";
            } else {
                $conditions[] = "DATE_FORMAT(m.data_vencimento, '%Y-%m') = :yearMonth";
            }
            $params[':yearMonth'] = $yearMonth;
        }

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }

        $query .= " ORDER BY a.nome_aluno, m.data_vencimento DESC";
        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => &$val) {
            $stmt->bindParam($key, $val);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function readById($id, $id_escola, $userRole)
    {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id_material = :id"; // <-- ALTERADO
        $params = [':id' => $id];

        if ($userRole !== 'superadmin') {
            $query .= " AND id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function exists($id_aluno, $data_vencimento, $valor_parcela, $id_escola)
    {
        $query = "SELECT COUNT(*) FROM " . $this->table_name . " 
                  WHERE id_aluno = :id_aluno 
                  AND data_vencimento = :data_vencimento
                  AND valor_parcela = :valor_parcela
                  AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_aluno', $id_aluno, PDO::PARAM_INT);
        $stmt->bindParam(':data_vencimento', $data_vencimento);
        $stmt->bindParam(':valor_parcela', $valor_parcela);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    public function create($data, $id_escola)
    {
        // A verificação de "exists" aqui é mais simples.
        // Só não cria se já existir uma parcela IGUAL (aluno, data, valor)
        if ($this->exists($data['id_aluno'], $data['data_vencimento'], $data['valor_parcela'], $id_escola)) {
            return false;
        }

        $query = "INSERT INTO " . $this->table_name . " 
                    (id_aluno, valor_parcela, data_vencimento, status, multa_aplicada, juros_aplicados, descricao, id_escola) 
                  VALUES 
                    (:id_aluno, :valor_parcela, :data_vencimento, 'open', 0, 0, :descricao, :id_escola)";
        $stmt = $this->conn->prepare($query);

        $descricao = $data['descricao'] ?? 'Parcela de Material';

        $stmt->bindParam(':id_aluno', $data['id_aluno']);
        $stmt->bindParam(':valor_parcela', $data['valor_parcela']); // <-- ALTERADO
        $stmt->bindParam(':data_vencimento', $data['data_vencimento']);
        $stmt->bindParam(':descricao', $descricao);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function registerPayment($data, $id_escola)
    {
        $query = "UPDATE " . $this->table_name . " SET data_pagamento = :data_pagamento, valor_pago = :valor_pago, status = 'approved' WHERE id_material = :id_material AND id_escola = :id_escola"; // <-- ALTERADO
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':data_pagamento', $data['data_pagamento']);
        $stmt->bindParam(':valor_pago', $data['valor_pago']);
        $stmt->bindParam(':id_material', $data['id_material']); // <-- ALTERADO
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function delete($id, $id_escola)
    {
        $query = "DELETE FROM " . $this->table_name . " WHERE id_material = :id AND id_escola = :id_escola"; // <-- ALTERADO
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    public function updateStatus($id, $status, $id_escola)
    {
        $query = "UPDATE " . $this->table_name . " SET status = :status WHERE id_material = :id AND id_escola = :id_escola"; // <-- ALTERADO
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function calcularEncargosAtraso($id, $id_escola, $userRole)
    {
        $material = $this->readById($id, $id_escola, $userRole); // <-- ALTERADO
        if (!$material)
            return false;

        $dataVencimento = new DateTime($material['data_vencimento']);
        $hoje = new DateTime();

        if ($hoje <= $dataVencimento) {
            return [
                'dias_atraso' => 0,
                'multa_aplicada' => 0.00,
                'juros_aplicados' => 0.00,
                'valor_total_devido' => (float) $material['valor_parcela'] // <-- ALTERADO
            ];
        }

        $diasAtraso = $hoje->diff($dataVencimento)->days;
        $valorBase = (float) $material['valor_parcela']; // <-- ALTERADO

        // ... (Sua lógica de juros copiada de Mensalidade.php) ...
        $juroDeMoraAbsoluto = 0.00;
        $juroDoMesAbsoluto = 0.00;

        if ($diasAtraso >= 30) {
            $blocosDe30Dias = floor($diasAtraso / 30);
            $taxaJuroDoMes = 2.0 * $blocosDe30Dias;
            $juroDoMesAbsoluto = ($taxaJuroDoMes / 100) * $valorBase;
        }

        if ($diasAtraso > 0) {
            $taxaMoraMensalFixa = 2.0;
            $taxaMoraDiaria = $taxaMoraMensalFixa / 30;
            $juroDeMoraAbsoluto = ($taxaMoraDiaria / 100) * $valorBase * $diasAtraso;
        }
        // ... (Fim da lógica de juros) ...

        $juroDoMesAbsoluto = round($juroDoMesAbsoluto, 2);
        $juroDeMoraAbsoluto = round($juroDeMoraAbsoluto, 2);

        $valorTotalDevido = $valorBase + $juroDoMesAbsoluto + $juroDeMoraAbsoluto;
        $valorTotalDevido = round($valorTotalDevido, 2);

        $query = "UPDATE " . $this->table_name . " SET 
                    dias_atraso = :dias_atraso,
                    multa_aplicada = :juro_do_mes,
                    juros_aplicados = :juro_de_mora
                  WHERE id_material = :id AND id_escola = :id_escola"; // <-- ALTERADO

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':dias_atraso', $diasAtraso);
        $stmt->bindParam(':juro_do_mes', $juroDoMesAbsoluto);
        $stmt->bindParam(':juro_de_mora', $juroDeMoraAbsoluto);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'dias_atraso' => $diasAtraso,
            'multa_aplicada' => $juroDoMesAbsoluto,
            'juros_aplicados' => $juroDeMoraAbsoluto,
            'valor_total_devido' => $valorTotalDevido
        ];
    }

    public function readByAlunoRa($ra_sef)
    {
        $alunoQuery = "SELECT id_aluno FROM alunos WHERE ra_sef = :ra_sef LIMIT 1";
        $stmtAluno = $this->conn->prepare($alunoQuery);
        $stmtAluno->bindParam(':ra_sef', $ra_sef);
        $stmtAluno->execute();
        $aluno = $stmtAluno->fetch(PDO::FETCH_ASSOC);

        if (!$aluno)
            return false;

        $id_aluno = $aluno['id_aluno'];
        $query = "SELECT * FROM " . $this->table_name . " m
                  WHERE 
                    m.id_aluno = :id_aluno
                  ORDER BY 
                    m.data_vencimento ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_aluno', $id_aluno, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    }

    public function readSummaryByTurma($id_escola, $userRole, $yearMonth)
    {
        $query = "
            SELECT 
                t.id_turma,
                t.nome_turma,
                SUM(CASE 
                    WHEN m.status = 'open' AND m.data_vencimento < CURDATE() THEN 1 
                    ELSE 0 
                END) as em_atraso,
                SUM(CASE 
                    WHEN m.status = 'open' AND m.data_vencimento >= CURDATE() THEN 1 
                    ELSE 0 
                END) as a_receber
            FROM turmas t
            LEFT JOIN alunos a ON t.id_turma = a.id_turma
            LEFT JOIN " . $this->table_name . " m ON a.id_aluno = m.id_aluno"; // <-- Usa a tabela de material

        $conditions = [];
        $params = [];

        if ($userRole !== 'superadmin') {
            $conditions[] = "t.id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        if ($yearMonth && $yearMonth !== 'todos') {
            $conditions[] = "DATE_FORMAT(m.data_vencimento, '%Y-%m') = :yearMonth";
            $params[':yearMonth'] = $yearMonth;
        }

        if (!empty($conditions)) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }

        $query .= " GROUP BY t.id_turma, t.nome_turma ORDER BY t.nome_turma ASC";

        $stmt = $this->conn->prepare($query);

        foreach ($params as $key => &$val) {
            $stmt->bindParam($key, $val);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}