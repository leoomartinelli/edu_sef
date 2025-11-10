<?php
// api/controllers/MensalidadeController.php

date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../models/Mensalidade.php';
require_once __DIR__ . '/../models/Aluno.php';

class MensalidadeController
{
    private $mensalidadeModel;

    public function __construct()
    {
        $this->mensalidadeModel = new Mensalidade();
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

    public function create()
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['id_aluno']) || empty($data['valor_mensalidade']) || empty($data['data_vencimento'])) {
            $this ->sendResponse(400, ['success' => false, 'message' => 'Dados incompletos. Por favor, forneça o aluno, o valor e a data de vencimento.']);
            return;
        }
        try {
            $startDate = new DateTime($data['data_vencimento']);
            $startMonth = (int) $startDate->format('m');
            $startYear = (int) $startDate->format('Y');
            $dueDay = (int) $startDate->format('d');
            $successCount = 0;
            $failCount = 0;
            $totalMonths = 0;
            for ($month = $startMonth; $month <= 12; $month++) {
                $totalMonths++;
                $currentDueDate = clone $startDate;
                $currentDueDate->setDate($startYear, $month, $dueDay);
                $monthlyData = ['id_aluno' => $data['id_aluno'], 'valor_mensalidade' => $data['valor_mensalidade'], 'data_vencimento' => $currentDueDate->format('Y-m-d')];
                if ($this->mensalidadeModel->create($monthlyData, $authData['id_escola'])) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }
            if ($successCount > 0) {
                $message = "{$successCount} de {$totalMonths} mensalidades foram criadas com sucesso.";
                if ($failCount > 0)
                    $message .= " {$failCount} falharam (possivelmente por já existirem).";
                $this->sendResponse(201, ['success' => true, 'message' => $message]);
            } else {
                $this->sendResponse(500, ['success' => false, 'message' => 'Nenhuma mensalidade pôde ser criada.']);
            }
        } catch (Exception $e) {
            $this ->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()]);
        }
    }

    public function getAll()
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this ->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        try {
            $searchTerm = $_GET['search'] ?? null;
            $status = $_GET['status'] ?? null;
            $mes = $_GET['mes'] ?? null;
            $id_turma = $_GET['turma_id'] ?? null;
            $id_aluno = $_GET['aluno_id'] ?? null;

            $mensalidades = $this->mensalidadeModel->readAll($authData['id_escola'], $authData['userRole'], $searchTerm, $status, $mes, $id_turma, $id_aluno);
            $mensalidades = $this->processarEncargos($mensalidades, $authData);

            $this ->sendResponse(200, ['success' => true, 'data' => $mensalidades]);
        } catch (Exception $e) {
            $this ->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor ao buscar mensalidades: ' . $e->getMessage()]);
        }
    }

    public function getSummaryByTurma()
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this ->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        try {
            $mes = $_GET['mes'] ?? date('Y-m');
            $summary = $this->mensalidadeModel->readSummaryByTurma($authData['id_escola'], $authData['userRole'], $mes);
            $this ->sendResponse(200, ['success' => true, 'data' => $summary]);
        } catch (Exception $e) {
            $this ->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor ao buscar resumo: ' . $e->getMessage()]);
        }
    }

    public function getByAluno($ra_sef) // Parâmetro agora é ra_sef
    {
        try {
            $mensalidades = $this->mensalidadeModel->readByAlunoRa($ra_sef); // Passa o ra_sef para a função do Model
            if ($mensalidades === false) {
                $this ->sendResponse(404, ['success' => false, 'message' => 'Aluno não encontrado com o RA SEF fornecido.']);
                return;
            }
            $mensalidades = $this->processarEncargos($mensalidades, null);
            $this ->sendResponse(200, ['success' => true, 'data' => $mensalidades]);
        } catch (Exception $e) {
            $this ->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor ao buscar mensalidades do aluno: ' . $e->getMessage()]);
        }
    }

    private function processarEncargos($mensalidades, $authData)
    {
        $hoje = new DateTime();
        $paidStatuses = ['approved', 'refunded', 'charged_back', 'cancelled'];

        foreach ($mensalidades as &$mensalidade) {
            $status = strtolower($mensalidade['status']);

            if (in_array($status, $paidStatuses)) {
                $mensalidade['valor_total_devido'] = (float) ($mensalidade['valor_pago'] ?? $mensalidade['valor_mensalidade']);
                continue;
            }

            $dataVencimento = new DateTime($mensalidade['data_vencimento']);

            if ($hoje > $dataVencimento && !in_array($status, $paidStatuses)) {
                if ($authData) {
                    $dadosCalculados = $this->mensalidadeModel->calcularEncargosAtraso($mensalidade['id_mensalidade'], $authData['id_escola'], $authData['userRole']);
                    if ($dadosCalculados) {
                        $mensalidade = array_merge($mensalidade, $dadosCalculados);
                    }
                }
            } else {
                $mensalidade['valor_total_devido'] = (float) $mensalidade['valor_mensalidade'];
            }
        }
        return $mensalidades;
    }

    public function registerPayment($id)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this ->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['valor_pago']) || empty($data['data_pagamento'])) {
            $this ->sendResponse(400, ['success' => false, 'message' => 'Dados de pagamento incompletos.']);
            return;
        }
        $data['id_mensalidade'] = $id;
        try {
            if ($this->mensalidadeModel->registerPayment($data, $authData['id_escola'])) {
                $this ->sendResponse(200, ['success' => true, 'message' => 'Pagamento registrado com sucesso.']);
            } else {
                $this ->sendResponse(500, ['success' => false, 'message' => 'Falha ao registrar o pagamento.']);
            }
        } catch (Exception $e) {
            $this ->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()]);
        }
    }

    public function delete($id)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this ->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        try {
            if ($this->mensalidadeModel->delete($id, $authData['id_escola'])) {
                $this ->sendResponse(200, ['success' => true, 'message' => 'Mensalidade deletada com sucesso.']);
            } else {
                $this ->sendResponse(404, ['success' => false, 'message' => 'Mensalidade não encontrada nesta escola.']);
            }
        } catch (Exception $e) {
            $this ->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor: ' . $e->getMessage()]);
        }
    }

    public function getMensalidadeDetails($id)
    {
        $userRole = $GLOBALS['user_data']['role'] ?? null;
        $username = $GLOBALS['user_data']['username'] ?? null;
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;

        $mensalidade = $this->mensalidadeModel->readById((int) $id, $id_escola, $userRole);
        if (!$mensalidade) {
            $this ->sendResponse(404, ['success' => false, 'message' => 'Mensalidade não encontrada.']);
            return;
        }
        if ($userRole === 'aluno') {
            $alunoModel = new Aluno();
            $alunoDaMensalidade = $alunoModel->getById($mensalidade['id_aluno'], $id_escola, $userRole);

            // =================================================================
            // ===               A CORREÇÃO 1 ESTÁ AQUI                  ===
            // =================================================================
            // Trocamos 'ra' por 'ra_sef' na verificação de segurança
            if (!$alunoDaMensalidade || $alunoDaMensalidade['ra_sef'] !== $username) {
                // =================================================================
                $this ->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
                return;
            }
        }
        $data = ['status' => $mensalidade['status'], 'pix_qr_code_base64' => $mensalidade['pix_qr_code_base64'], 'pix_copia_e_cola' => $mensalidade['pix_copia_e_cola'], 'pix_expiration_time' => $mensalidade['pix_expiration_time']];
        $this ->sendResponse(200, ['success' => true, 'data' => $data]);
    }

    public function updateStudentMensalidadeStatus($id)
    {
        $alunoRa = $GLOBALS['user_data']['username'] ?? null; // Este $alunoRa é o ra_sef
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        $userRole = $GLOBALS['user_data']['role'] ?? null;

        if (!$alunoRa) {
            $this ->sendResponse(401, ['success' => false, 'message' => 'Não foi possível identificar o aluno a partir do token.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $newStatus = $data['status'] ?? null;
        if (empty($newStatus)) {
            $this ->sendResponse(400, ['success' => false, 'message' => 'O novo status não foi fornecido.']);
            return;
        }
        $mensalidade = $this->mensalidadeModel->readById((int) $id, $id_escola, $userRole);
        if (!$mensalidade) {
            $this ->sendResponse(404, ['success' => false, 'message' => 'Mensalidade não encontrada.']);
            return;
        }
        $alunoModel = new Aluno();
        $alunoDaMensalidade = $alunoModel->getById($mensalidade['id_aluno'], $id_escola, $userRole);

        // =================================================================
        // ===               A CORREÇÃO 2 ESTÁ AQUI                  ===
        // =================================================================
        // Trocamos 'ra' por 'ra_sef' na verificação de segurança
        if (!$alunoDaMensalidade || $alunoDaMensalidade['ra_sef'] !== $alunoRa) {
            // =================================================================
            $this ->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Esta mensalidade não pertence a você.']);
            return;
        }
        if ($this->mensalidadeModel->updateStatus((int) $id, $newStatus, $id_escola)) {
            $this ->sendResponse(200, ['success' => true, 'message' => 'Status da mensalidade atualizado com sucesso.']);
        } else {
            $this ->sendResponse(500, ['success' => false, 'message' => 'Falha ao atualizar o status da mensalidade.']);
        }
    }
}