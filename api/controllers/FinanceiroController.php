<?php
// api/controllers/FinanceiroController.php

require_once __DIR__ . '/../models/Financeiro.php';

class FinanceiroController
{
    private $financeiroModel;

    public function __construct()
    {
        $this->financeiroModel = new Financeiro();
    }

    private function sendResponse($data, $statusCode = 200)
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
    }

    // <-- ALTERADO: Função auxiliar para segurança
    private function getAuthData()
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        $userRole = $GLOBALS['user_data']['role'] ?? null;

        if ($userRole !== 'superadmin' && !$id_escola) {
            return null;
        }
        return ['id_escola' => $id_escola, 'userRole' => $userRole];
    }

    public function getTiposCusto()
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(['success' => false, 'message' => 'Acesso negado.'], 403);
            return;
        }

        $tipos = $this->financeiroModel->findAllTiposCusto($authData['id_escola'], $authData['userRole']);
        $this->sendResponse(['success' => true, 'data' => $tipos]);
    }

    public function createTipoCusto()
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(['success' => false, 'message' => 'Acesso negado.'], 403);
            return;
        }

        $data = json_decode(file_get_contents('php://input'));
        if (!isset($data->nome) || empty(trim($data->nome))) {
            $this->sendResponse(['success' => false, 'message' => 'O nome do tipo de custo é obrigatório.'], 400);
            return;
        }
        $result = $this->financeiroModel->createTipoCusto(trim($data->nome), $authData['id_escola']);
        $this->sendResponse($result);
    }

    public function getTiposReceita()
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(['success' => false, 'message' => 'Acesso negado.'], 403);
            return;
        }

        $tipos = $this->financeiroModel->findAllTiposReceita($authData['id_escola'], $authData['userRole']);
        $this->sendResponse(['success' => true, 'data' => $tipos]);
    }

    public function createTipoReceita()
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(['success' => false, 'message' => 'Acesso negado.'], 403);
            return;
        }

        $data = json_decode(file_get_contents('php://input'));
        if (!isset($data->nome) || empty(trim($data->nome))) {
            $this->sendResponse(['success' => false, 'message' => 'O nome do tipo de receita é obrigatório.'], 400);
            return;
        }
        $result = $this->financeiroModel->createTipoReceita(trim($data->nome), $authData['id_escola']);
        $this->sendResponse($result);
    }

    public function getTransacoes()
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(['success' => false, 'message' => 'Acesso negado.'], 403);
            return;
        }

        $transacoes = $this->financeiroModel->findAllTransacoes($authData['id_escola'], $authData['userRole']);
        $this->sendResponse(['success' => true, 'data' => $transacoes]);
    }

    public function createTransacao()
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(['success' => false, 'message' => 'Acesso negado.'], 403);
            return;
        }

        $data = json_decode(file_get_contents('php://input'));
        $data->id_tipo_receita = $data->id_tipo_receita ?? null;
        $data->id_tipo_custo = $data->id_tipo_custo ?? null;

        $result = $this->financeiroModel->createTransacao($data, $authData['id_escola']);
        $this->sendResponse($result);
    }

    public function getApprovedMensalidades()
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(['success' => false, 'message' => 'Acesso negado.'], 403);
            return;
        }

        $mensalidades = $this->financeiroModel->findAllApprovedMensalidades($authData['id_escola'], $authData['userRole']);
        $this->sendResponse(['success' => true, 'data' => $mensalidades]);
    }

    public function getDashboardSummary()
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(['success' => false, 'message' => 'Acesso negado.'], 403);
            return;
        }

        $mes = $_GET['mes'] ?? date('Y-m');
        require_once __DIR__ . '/../models/Mensalidade.php';
        $mensalidadeModel = new Mensalidade();

        // IMPORTANTE: Os métodos countPaidInMonth e countPendingInMonth no Mensalidade.php também precisarão ser alterados
        // para receber e filtrar pelo id_escola.
        $pagasNoMes = $mensalidadeModel->countPaidInMonth($mes, $authData['id_escola']);
        $pendentesNoMes = $mensalidadeModel->countPendingInMonth($mes, $authData['id_escola']);

        $summaryData = [
            'pagas_no_mes' => $pagasNoMes,
            'pendentes_no_mes' => $pendentesNoMes
        ];

        $this->sendResponse(['success' => true, 'data' => $summaryData]);
    }

    public function deleteTransacoes()
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(['success' => false, 'message' => 'Acesso negado.'], 403);
            return;
        }

        $data = json_decode(file_get_contents('php://input'));

        if (!isset($data->ids) || !is_array($data->ids) || empty($data->ids)) {
            $this->sendResponse(['success' => false, 'message' => 'Nenhum ID de transação foi fornecido.'], 400);
            return;
        }

        // Garante que todos os IDs são inteiros para segurança
        $ids = array_map('intval', $data->ids);

        $result = $this->financeiroModel->deleteTransacoesByIds($ids, $authData['id_escola']);
        $this->sendResponse($result);
    }

    
}