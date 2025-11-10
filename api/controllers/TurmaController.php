<?php
// api/controllers/TurmaController.php

require_once __DIR__ . '/../models/Turma.php';

class TurmaController
{
    private $model;

    public function __construct()
    {
        $this->model = new Turma();
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

    private function sendResponse($statusCode, $data)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code($statusCode);
        }
        echo json_encode($data);
    }

    public function getAll()
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $turmas = $this->model->getAll($authData['id_escola'], $authData['userRole']);
        $this->sendResponse(200, ['success' => true, 'data' => $turmas]);
    }

    public function getById($id)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        if (!isset($id)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID da turma ausente.']);
            return;
        }

        $turma = $this->model->getById($id, $authData['id_escola'], $authData['userRole']);
        if ($turma) {
            $this->sendResponse(200, ['success' => true, 'data' => $turma]);
        } else {
            $this->sendResponse(404, ['success' => false, 'message' => 'Turma não encontrada.']);
        }
    }

    public function create()
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $json_data = file_get_contents('php://input');
        $data = json_decode($json_data, true);

        // Lógica de criação única (a mais comum)
        if (empty($data['nome_turma'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campo obrigatório (nome_turma) ausente.']);
            return;
        }

        $result = $this->model->create($data, $authData['id_escola']);

        if (is_numeric($result)) {
            $novaTurmaId = $result;
            $novaTurmaData = $this->model->getById($novaTurmaId, $authData['id_escola'], $authData['userRole']);
            $this->sendResponse(201, ['success' => true, 'message' => 'Turma criada com sucesso!', 'data' => $novaTurmaData]);
        } elseif ($result === 'nome_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Já existe uma turma com este nome nesta escola.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao criar turma.']);
        }
    }

    public function update($id)
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $json_data = file_get_contents('php://input');
        $data = json_decode($json_data, true);

        if (!isset($id) || !isset($data['nome_turma'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios (id_turma, nome_turma) ausentes.']);
            return;
        }
        $data['id_turma'] = $id;

        $result = $this->model->update($data, $authData['id_escola']);
        if ($result === true) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Turma atualizada com sucesso!']);
        } elseif ($result === 'nome_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Já existe outra turma com este nome nesta escola.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao atualizar turma.']);
        }
    }

    public function delete($id)
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        if (!isset($id)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID da turma ausente.']);
            return;
        }

        if ($this->model->delete($id, $authData['id_escola'])) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Turma excluída com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao excluir turma ou turma não encontrada nesta escola.']);
        }
    }
}