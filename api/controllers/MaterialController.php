<?php
// api/controllers/MaterialController.php

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../models/Material.php'; // <-- NOVO MODELO
require_once __DIR__ . '/../models/Aluno.php';

class MaterialController
{
    private $materialModel; // <-- ALTERADO

    public function __construct()
    {
        $this->materialModel = new Material(); // <-- ALTERADO
    }

    private function sendResponse($statusCode, $data)
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
    }

    private function getAuthData()
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        $userRole = $GLOBALS['user_data']['role'] ?? null;

        if ($userRole !== 'superadmin' && !$id_escola) {
            return null;
        }
        return ['id_escola' => $id_escola, 'userRole' => $userRole];
    }

    /**
     * Cria parcelas de material didático.
     * Esta é a lógica que você queria: ela recebe um valor total,
     * um número de parcelas e a data do primeiro vencimento,
     * e então cria as parcelas em um loop.
     */
    public function create()
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        // Validação dos campos
        if (empty($data['id_aluno']) || empty($data['valor_total_material']) || empty($data['numero_parcelas']) || empty($data['data_primeiro_vencimento'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Dados incompletos. Forneça id_aluno, valor_total_material, numero_parcelas e data_primeiro_vencimento.']);
            return;
        }

        try {
            $id_aluno = (int) $data['id_aluno'];
            $valorTotal = (float) $data['valor_total_material'];
            $numParcelas = (int) $data['numero_parcelas'];
            $primeiroVencimento = new DateTime($data['data_primeiro_vencimento']);

            if ($valorTotal <= 0 || $numParcelas <= 0) {
                $this->sendResponse(400, ['success' => false, 'message' => 'Valores e número de parcelas devem ser maiores que zero.']);
                return;
            }

            // Calcula o valor de cada parcela
            $valorParcela = round($valorTotal / $numParcelas, 2);

            // Ajusta a última parcela para bater o valor total (evita erros de centavos)
            $valorTotalCalculado = $valorParcela * ($numParcelas - 1);
            $valorUltimaParcela = $valorTotal - $valorTotalCalculado;

            $successCount = 0;
            $failCount = 0;

            for ($i = 0; $i < $numParcelas; $i++) {
                $currentDueDate = clone $primeiroVencimento;
                if ($i > 0) {
                    $currentDueDate->modify("+{$i} months");
                }

                $valorDaParcelaAtual = ($i == $numParcelas - 1) ? $valorUltimaParcela : $valorParcela;

                $parcelaData = [
                    'id_aluno' => $id_aluno,
                    'valor_parcela' => $valorDaParcelaAtual,
                    'data_vencimento' => $currentDueDate->format('Y-m-d'),
                    'descricao' => "Material Didático " . ($i + 1) . "/{$numParcelas}"
                ];

                if ($this->materialModel->create($parcelaData, $authData['id_escola'])) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }

            if ($successCount > 0) {
                $message = "{$successCount} de {$numParcelas} parcelas de material foram criadas com sucesso.";
                if ($failCount > 0)
                    $message .= " {$failCount} falharam (possivelmente por já existirem).";
                $this->sendResponse(201, ['success' => true, 'message' => $message]);
            } else {
                $this->sendResponse(500, ['success' => false, 'message' => 'Nenhuma parcela de material pôde ser criada.']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()]);
        }
    }

    public function getAll()
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        try {
            $searchTerm = $_GET['search'] ?? null;
            $status = $_GET['status'] ?? null;
            $mes = $_GET['mes'] ?? null;
            $id_turma = $_GET['turma_id'] ?? null;
            $id_aluno = $_GET['aluno_id'] ?? null;

            $materiais = $this->materialModel->readAll($authData['id_escola'], $authData['userRole'], $searchTerm, $status, $mes, $id_turma, $id_aluno);
            $materiais = $this->processarEncargos($materiais, $authData); // <-- Reutiliza a função de encargos

            $this->sendResponse(200, ['success' => true, 'data' => $materiais]);
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor ao buscar parcelas de material: ' . $e->getMessage()]);
        }
    }

    public function getByAluno($ra_sef)
    {
        try {
            $materiais = $this->materialModel->readByAlunoRa($ra_sef); // <-- ALTERADO
            if ($materiais === false) {
                $this->sendResponse(404, ['success' => false, 'message' => 'Aluno não encontrado com o RA SEF fornecido.']);
                return;
            }
            $materiais = $this->processarEncargos($materiais, null); // Encargos para o portal do aluno
            $this->sendResponse(200, ['success' => true, 'data' => $materiais]);
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor ao buscar material do aluno: ' . $e->getMessage()]);
        }
    }

    private function processarEncargos($materiais, $authData)
    {
        $hoje = new DateTime();
        $paidStatuses = ['approved', 'refunded', 'charged_back', 'cancelled'];

        foreach ($materiais as &$material) { // <-- Mudei o nome da variável
            $status = strtolower($material['status']);

            if (in_array($status, $paidStatuses)) {
                $material['valor_total_devido'] = (float) ($material['valor_pago'] ?? $material['valor_parcela']); // <-- ALTERADO
                continue;
            }

            $dataVencimento = new DateTime($material['data_vencimento']);

            if ($hoje > $dataVencimento && !in_array($status, $paidStatuses)) {
                if ($authData) {
                    // Chama o cálculo de encargos do NOVO model
                    $dadosCalculados = $this->materialModel->calcularEncargosAtrao($material['id_material'], $authData['id_escola'], $authData['userRole']); // <-- ALTERADO
                    if ($dadosCalculados) {
                        $material = array_merge($material, $dadosCalculados);
                    }
                }
            } else {
                $material['valor_total_devido'] = (float) $material['valor_parcela']; // <-- ALTERADO
            }
        }
        return $materiais;
    }

    public function registerPayment($id)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['valor_pago']) || empty($data['data_pagamento'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Dados de pagamento incompletos.']);
            return;
        }
        $data['id_material'] = $id; // <-- ALTERADO
        try {
            if ($this->materialModel->registerPayment($data, $authData['id_escola'])) { // <-- ALTERADO
                $this->sendResponse(200, ['success' => true, 'message' => 'Pagamento de material registrado com sucesso.']);
            } else {
                $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao registrar o pagamento do material.']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()]);
        }
    }

    public function delete($id)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        try {
            if ($this->materialModel->delete($id, $authData['id_escola'])) { // <-- ALTERADO
                $this->sendResponse(200, ['success' => true, 'message' => 'Parcela de material deletada com sucesso.']);
            } else {
                $this->sendResponse(404, ['success' => false, 'message' => 'Parcela de material não encontrada nesta escola.']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()]);
        }
    }

    public function getMaterialDetails($id) // <-- ALTERADO
    {
        $userRole = $GLOBALS['user_data']['role'] ?? null;
        $username = $GLOBALS['user_data']['username'] ?? null;
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;

        $material = $this->materialModel->readById((int) $id, $id_escola, $userRole); // <-- ALTERADO
        if (!$material) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Parcela de material não encontrada.']); // <-- ALTERADO
            return;
        }
        if ($userRole === 'aluno') {
            $alunoModel = new Aluno();
            $alunoDoMaterial = $alunoModel->getById($material['id_aluno'], $id_escola, $userRole); // <-- ALTERADO

            if (!$alunoDoMaterial || $alunoDoMaterial['ra_sef'] !== $username) {
                $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
                return;
            }
        }
        // Retorna os dados do PIX, etc.
        $data = [
            'status' => $material['status'],
            'pix_qr_code_base64' => $material['pix_qr_code_base64'],
            'pix_copia_e_cola' => $material['pix_copia_e_cola'],
            'pix_expiration_time' => $material['pix_expiration_time']
        ];
        $this->sendResponse(200, ['success' => true, 'data' => $data]);
    }

    public function updateStudentMaterialStatus($id) // <-- ALTERADO
    {
        $alunoRa = $GLOBALS['user_data']['username'] ?? null;
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        $userRole = $GLOBALS['user_data']['role'] ?? null;

        if (!$alunoRa) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Não foi possível identificar o aluno a partir do token.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $newStatus = $data['status'] ?? null;
        if (empty($newStatus)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'O novo status não foi fornecido.']);
            return;
        }

        $material = $this->materialModel->readById((int) $id, $id_escola, $userRole); // <-- ALTERADO
        if (!$material) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Parcela de material não encontrada.']); // <-- ALTERADO
            return;
        }

        $alunoModel = new Aluno();
        $alunoDoMaterial = $alunoModel->getById($material['id_aluno'], $id_escola, $userRole); // <-- ALTERADO

        if (!$alunoDoMaterial || $alunoDoMaterial['ra_sef'] !== $alunoRa) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Esta parcela de material não pertence a você.']); // <-- ALTERADO
            return;
        }

        if ($this->materialModel->updateStatus((int) $id, $newStatus, $id_escola)) { // <-- ALTERADO
            $this->sendResponse(200, ['success' => true, 'message' => 'Status da parcela de material atualizado com sucesso.']); // <-- ALTERADO
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao atualizar o status da parcela de material.']); // <-- ALTERADO
        }
    }

    public function getSummaryByTurma()
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        try {
            $mes = $_GET['mes'] ?? date('Y-m');
            // Chama o método no Model (que vamos criar a seguir)
            $summary = $this->materialModel->readSummaryByTurma($authData['id_escola'], $authData['userRole'], $mes);
            $this->sendResponse(200, ['success' => true, 'data' => $summary]);
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor ao buscar resumo: ' . $e->getMessage()]);
        }
    }
}