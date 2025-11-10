<?php
// api/models/Mensalidade.php

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../../config/Database.php';

class Mensalidade
{
    private $conn;
    private $table_name = "mensalidades";

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    // <-- ALTERADO: Adicionados parâmetros de autenticação
    public function readAll($id_escola, $userRole, $searchTerm = null, $status = null, $yearMonth = null, $id_turma = null, $id_aluno = null)
    {
        $query = "SELECT 
            m.id_mensalidade, m.id_aluno, a.nome_aluno, r.nome as nome_resp_financeiro,
            m.valor_mensalidade, m.data_vencimento, m.data_pagamento,
            m.valor_pago, m.status, m.multa_aplicada, m.juros_aplicados
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

        // <-- NOVO: Filtro por ID do aluno -->
        if ($id_aluno) {
            $conditions[] = "m.id_aluno = :id_aluno";
            $params[':id_aluno'] = $id_aluno;
        }

        if ($searchTerm) {
            $conditions[] = "(a.nome_aluno LIKE :searchTerm OR r.nome LIKE :searchTerm)";
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

    // <-- ALTERADO: Adicionados parâmetros de autenticação
    public function readById($id, $id_escola, $userRole)
    {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id_mensalidade = :id";
        $params = [':id' => $id];

        // <-- ALTERADO: Filtro de segurança
        if ($userRole !== 'superadmin') {
            $query .= " AND id_escola = :id_escola";
            $params[':id_escola'] = $id_escola;
        }

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
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
            LEFT JOIN mensalidades m ON a.id_aluno = m.id_aluno";

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

    private function exists($id_aluno, $data_vencimento, $id_escola)
    {
        $date = new DateTime($data_vencimento);
        $month = $date->format('m');
        $year = $date->format('Y');
        // <-- ALTERADO: Adicionado filtro de escola
        $query = "SELECT COUNT(*) FROM " . $this->table_name . " 
                  WHERE id_aluno = :id_aluno 
                  AND MONTH(data_vencimento) = :month 
                  AND YEAR(data_vencimento) = :year
                  AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_aluno', $id_aluno, PDO::PARAM_INT);
        $stmt->bindParam(':month', $month);
        $stmt->bindParam(':year', $year);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function create($data, $id_escola)
    {
        if ($this->exists($data['id_aluno'], $data['data_vencimento'], $id_escola) && ($data['descricao'] ?? 'Mensalidade') === 'Mensalidade') {
            return false;
        }

        // <-- ALTERADO: Adicionado campo id_escola
        $query = "INSERT INTO " . $this->table_name . " 
                    (id_aluno, valor_mensalidade, data_vencimento, status, multa_aplicada, juros_aplicados, descricao, id_escola) 
                  VALUES 
                    (:id_aluno, :valor_mensalidade, :data_vencimento, 'open', 0, 0, :descricao, :id_escola)";
        $stmt = $this->conn->prepare($query);

        $descricao = $data['descricao'] ?? 'Mensalidade';

        $stmt->bindParam(':id_aluno', $data['id_aluno']);
        $stmt->bindParam(':valor_mensalidade', $data['valor_mensalidade']);
        $stmt->bindParam(':data_vencimento', $data['data_vencimento']);
        $stmt->bindParam(':descricao', $descricao);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        return $stmt->execute();
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function registerPayment($data, $id_escola)
    {
        // <-- ALTERADO: Filtro de segurança por escola
        $query = "UPDATE " . $this->table_name . " SET data_pagamento = :data_pagamento, valor_pago = :valor_pago, status = 'approved' WHERE id_mensalidade = :id_mensalidade AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':data_pagamento', $data['data_pagamento']);
        $stmt->bindParam(':valor_pago', $data['valor_pago']);
        $stmt->bindParam(':id_mensalidade', $data['id_mensalidade']);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        return $stmt->execute();
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function delete($id, $id_escola)
    {
        // <-- ALTERADO: Filtro de segurança por escola
        $query = "DELETE FROM " . $this->table_name . " WHERE id_mensalidade = :id AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        return $stmt->execute() && $stmt->rowCount() > 0;
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function updateStatus($id, $status, $id_escola)
    {
        // <-- ALTERADO: Filtro de segurança por escola
        $query = "UPDATE " . $this->table_name . " SET status = :status WHERE id_mensalidade = :id AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        return $stmt->execute();
    }

    // <-- ALTERADO: Adicionados parâmetros de autenticação
    public function calcularEncargosAtraso($id, $id_escola, $userRole)
    {
        $mensalidade = $this->readById($id, $id_escola, $userRole);
        if (!$mensalidade)
            return false;

        $dataVencimento = new DateTime($mensalidade['data_vencimento']);
        $hoje = new DateTime();

        if ($hoje <= $dataVencimento) {
            return [
                'dias_atraso' => 0,
                'multa_aplicada' => 0.00,
                'juros_aplicados' => 0.00,
                'valor_total_devido' => (float) $mensalidade['valor_mensalidade']
            ];
        }

        $diasAtraso = $hoje->diff($dataVencimento)->days;
        $valorBase = (float) $mensalidade['valor_mensalidade'];
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

        $juroDoMesAbsoluto = round($juroDoMesAbsoluto, 2);
        $juroDeMoraAbsoluto = round($juroDeMoraAbsoluto, 2);

        $valorTotalDevido = $valorBase + $juroDoMesAbsoluto + $juroDeMoraAbsoluto;
        $valorTotalDevido = round($valorTotalDevido, 2);

        // <-- ALTERADO: Filtro de segurança por escola
        $query = "UPDATE " . $this->table_name . " SET 
                    dias_atraso = :dias_atraso,
                    multa_aplicada = :juro_do_mes,
                    juros_aplicados = :juro_de_mora
                  WHERE id_mensalidade = :id AND id_escola = :id_escola";

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

    public function readByAlunoRa($ra_sef) // Mudei o nome do parâmetro para ra_sef para ficar claro
    {
        // A segurança aqui é implícita, pois o RA é único e o acesso à rota é limitado ao próprio aluno.

        // =================================================================
        // ===               A CORREÇÃO ESTÁ AQUI                      ===
        // =================================================================
        // Trocamos 'ra = :ra' por 'ra_sef = :ra_sef'
        $alunoQuery = "SELECT id_aluno FROM alunos WHERE ra_sef = :ra_sef LIMIT 1";
        $stmtAluno = $this->conn->prepare($alunoQuery);
        $stmtAluno->bindParam(':ra_sef', $ra_sef); // Bind com o novo parâmetro
        // =================================================================

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

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function countPaidInMonth($yearMonth, $id_escola)
    {
        // <-- ALTERADO: Adicionado filtro de escola
        $query = "SELECT COUNT(id_mensalidade) as total 
                  FROM " . $this->table_name . " 
                  WHERE status = 'approved' AND DATE_FORMAT(data_pagamento, '%Y-%m') = :yearMonth AND id_escola = :id_escola";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':yearMonth', $yearMonth);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    // <-- ALTERADO: Adicionado parâmetro $id_escola
    public function countPendingInMonth($yearMonth, $id_escola)
    {
        // <-- ALTERADO: Adicionado filtro de escola
        $query = "SELECT COUNT(id_mensalidade) as total 
                  FROM " . $this->table_name . " 
                  WHERE status = 'open' AND DATE_FORMAT(data_vencimento, '%Y-%m') = :yearMonth AND id_escola = :id_escola";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':yearMonth', $yearMonth);
        $stmt->bindParam(':id_escola', $id_escola, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}