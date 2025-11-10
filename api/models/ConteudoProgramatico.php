<?php
// api/models/ConteudoProgramatico.php

require_once __DIR__ . '/../../config/Database.php';

class ConteudoProgramatico
{
    private $conn;
    private $table_name = "conteudo_programatico";
    private $lancamentos_table = "lancamentos_progresso";

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    // Cria uma nova meta de conteúdo programático (para admins)
    public function create($data, $id_escola)
    {
        $query = "INSERT INTO " . $this->table_name . " (id_turma, id_disciplina, bimestre, titulo, descricao, data_inicio_prevista, data_fim_prevista, meta_pagina_inicio, meta_pagina_fim, id_escola) VALUES (:id_turma, :id_disciplina, :bimestre, :titulo, :descricao, :data_inicio_prevista, :data_fim_prevista, :meta_pagina_inicio, :meta_pagina_fim, :id_escola)";

        $stmt = $this->conn->prepare($query);

        // Limpeza de dados
        $stmt->bindParam(":id_turma", $data['id_turma'], PDO::PARAM_INT);
        $stmt->bindParam(":id_disciplina", $data['id_disciplina'], PDO::PARAM_INT);
        $stmt->bindParam(":bimestre", $data['bimestre'], PDO::PARAM_INT);
        $stmt->bindParam(":titulo", $data['titulo']);
        $stmt->bindParam(":descricao", $data['descricao']);
        $stmt->bindParam(":data_inicio_prevista", $data['data_inicio_prevista']);
        $stmt->bindParam(":data_fim_prevista", $data['data_fim_prevista']);
        $stmt->bindParam(":meta_pagina_inicio", $data['meta_pagina_inicio'], PDO::PARAM_INT);
        $stmt->bindParam(":meta_pagina_fim", $data['meta_pagina_fim'], PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao criar conteúdo programático: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    // Busca todos os conteúdos programáticos de uma turma
    public function getByTurma($id_turma, $id_escola)
    {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id_turma = :id_turma AND id_escola = :id_escola ORDER BY data_inicio_prevista ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_turma", $id_turma, PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Busca um conteúdo específico por ID
    public function getById($id_conteudo, $id_escola)
    {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id_conteudo = :id_conteudo AND id_escola = :id_escola";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id_conteudo", $id_conteudo, PDO::PARAM_INT);
        $stmt->bindParam(":id_escola", $id_escola, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    // Lançamento de progresso pelo professor
    public function addProgresso($data, $id_professor)
    {
        $query = "INSERT INTO " . $this->lancamentos_table . " (id_conteudo, id_professor, data_aula, paginas_concluidas_inicio, paginas_concluidas_fim, observacoes) VALUES (:id_conteudo, :id_professor, :data_aula, :paginas_concluidas_inicio, :paginas_concluidas_fim, :observacoes)";
        $stmt = $this->conn->prepare($query);

        $stmt->bindParam(":id_conteudo", $data['id_conteudo'], PDO::PARAM_INT);
        $stmt->bindParam(":id_professor", $id_professor, PDO::PARAM_INT);
        $stmt->bindParam(":data_aula", $data['data_aula']);
        $stmt->bindParam(":paginas_concluidas_inicio", $data['paginas_concluidas_inicio'], PDO::PARAM_INT);
        $stmt->bindParam(":paginas_concluidas_fim", $data['paginas_concluidas_fim'], PDO::PARAM_INT);
        $stmt->bindParam(":observacoes", $data['observacoes']);

        if ($stmt->execute()) {
            return true;
        }
        error_log("Erro ao lançar progresso: " . implode(" ", $stmt->errorInfo()));
        return false;
    }

    // A MÁGICA: Pega os dados para gerar o gráfico
    public function getProgressoParaGrafico($id_conteudo)
    {
        // 1. Pega a meta
        $metaQuery = "SELECT * FROM " . $this->table_name . " WHERE id_conteudo = :id_conteudo";
        $metaStmt = $this->conn->prepare($metaQuery);
        $metaStmt->bindParam(":id_conteudo", $id_conteudo, PDO::PARAM_INT);
        $metaStmt->execute();
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC);

        if (!$meta)
            return null;

        // 2. Pega os lançamentos reais
        $lancamentosQuery = "
            SELECT l.*, p.nome_professor 
            FROM " . $this->lancamentos_table . " l
            LEFT JOIN professores p ON l.id_professor = p.id_professor
            WHERE l.id_conteudo = :id_conteudo 
            ORDER BY l.data_aula ASC
        ";
        $lancamentosStmt = $this->conn->prepare($lancamentosQuery);
        $lancamentosStmt->bindParam(":id_conteudo", $id_conteudo, PDO::PARAM_INT);
        $lancamentosStmt->execute();
        $lancamentos = $lancamentosStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Processa os dados para o gráfico
        $dataInicio = new DateTime($meta['data_inicio_prevista']);
        $dataFim = new DateTime($meta['data_fim_prevista']);
        $hoje = new DateTime();

        $totalPaginasMeta = ($meta['meta_pagina_fim'] - $meta['meta_pagina_inicio']) + 1;
        $intervalo = $dataInicio->diff($dataFim);
        $totalDiasPrevistos = $intervalo->days + 1;
        $paginasPorDiaEsperado = $totalPaginasMeta / $totalDiasPrevistos;

        $graficoData = [];
        $progressoRealAcumulado = 0;
        $progressoEsperadoAcumulado = 0;

        $lancamentosPorData = [];
        foreach ($lancamentos as $lancamento) {
            $paginasFeitas = ($lancamento['paginas_concluidas_fim'] - $lancamento['paginas_concluidas_inicio']) + 1;
            $lancamentosPorData[$lancamento['data_aula']] = ($lancamentosPorData[$lancamento['data_aula']] ?? 0) + $paginasFeitas;
        }

        $currentDate = clone $dataInicio;
        while ($currentDate <= $dataFim && $currentDate <= $hoje) {
            $dataStr = $currentDate->format('Y-m-d');
            $progressoEsperadoAcumulado += $paginasPorDiaEsperado;
            if (isset($lancamentosPorData[$dataStr])) {
                $progressoRealAcumulado += $lancamentosPorData[$dataStr];
            }

            $graficoData[] = [
                'data' => $dataStr,
                // A "linha imaginária" - representa a página que o professor DEVERIA ter alcançado
                'progresso_esperado' => round($meta['meta_pagina_inicio'] + $progressoEsperadoAcumulado - 1),
                // Onde o professor realmente está
                'progresso_real' => $meta['meta_pagina_inicio'] + $progressoRealAcumulado - 1
            ];

            $currentDate->modify('+1 day');
        }

        return ['meta' => $meta, 'progresso' => $graficoData, 'historico' => $lancamentos];
    }

    
}