<?php
// api/controllers/DisciplinaController.php

require_once __DIR__ . '/../models/Disciplina.php';
require_once __DIR__ . '/../models/Turma.php'; // Para verificar a posse da turma

class DisciplinaController
{
    private $model;
    private $turmaModel;

    public function __construct()
    {
        $this->model = new Disciplina();
        $this->turmaModel = new Turma();
    }

    private function sendResponse($statusCode, $data)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code($statusCode);
        }
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

    public function getAll()
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $disciplinas = $this->model->getAll($authData['id_escola'], $authData['userRole']);
        $this->sendResponse(200, ['success' => true, 'data' => $disciplinas]);
    }

    public function getById($id)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $disciplina = $this->model->getById((int) $id, $authData['id_escola'], $authData['userRole']);
        if ($disciplina) {
            $this->sendResponse(200, ['success' => true, 'data' => $disciplina]);
        } else {
            $this->sendResponse(404, ['success' => false, 'message' => 'Disciplina não encontrada.']);
        }
    }

    public function create()
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        $result = $this->model->create($data, $authData['id_escola']);

        if ($result === true) {
            $this->sendResponse(201, ['success' => true, 'message' => 'Disciplina criada com sucesso!']);
        } elseif ($result === 'name_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Já existe uma disciplina com este nome.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao criar disciplina.']);
        }
    }

    public function update($id)
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $data['id_disciplina'] = (int) $id;

        $result = $this->model->update($data, $authData['id_escola']);

        if ($result === true) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Disciplina atualizada com sucesso!']);
        } elseif ($result === 'name_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Já existe outra disciplina com este nome.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao atualizar disciplina.']);
        }
    }

    public function delete($id)
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        if ($this->model->delete((int) $id, $authData['id_escola'])) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Disciplina excluída com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao excluir disciplina ou disciplina não encontrada nesta escola.']);
        }
    }

    // --- Métodos de Associação (com segurança adicionada) ---

    public function getTurmaDisciplinas($idTurma)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        // Lembre-se que Turma.php precisa ser adaptado para filtrar por escola
        $turma = $this->turmaModel->getById((int) $idTurma, $authData['id_escola'], $authData['userRole']);
        if (!$turma) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Turma não encontrada nesta escola.']);
            return;
        }

        $disciplinas = $this->model->getDisciplinasByTurmaId((int) $idTurma);
        $this->sendResponse(200, ['success' => true, 'data' => $disciplinas]);
    }

    public function addDisciplinaToTurma($idTurma)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $idDisciplina = $data['id_disciplina'] ?? null;

        // Verifica se a turma e a disciplina pertencem à escola do admin
        $turma = $this->turmaModel->getById((int) $idTurma, $authData['id_escola'], $authData['userRole']);
        $disciplina = $this->model->getById((int) $idDisciplina, $authData['id_escola'], $authData['userRole']);

        if (!$turma || !$disciplina) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Turma ou Disciplina não encontrada nesta escola.']);
            return;
        }

        $result = $this->model->addDisciplinaToTurma((int) $idTurma, (int) $idDisciplina);

        if ($result === true) {
            $this->sendResponse(201, ['success' => true, 'message' => 'Disciplina associada à turma com sucesso!']);
        } elseif ($result === 'already_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Esta disciplina já está associada a esta turma.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao associar disciplina à turma.']);
        }
    }

    public function removeDisciplinaFromTurma($idTurma, $idDisciplina)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        // Verifica se a turma pertence à escola do admin antes de remover a associação
        $turma = $this->turmaModel->getById((int) $idTurma, $authData['id_escola'], $authData['userRole']);
        if (!$turma) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Turma não encontrada nesta escola.']);
            return;
        }

        if ($this->model->removeDisciplinaFromTurma((int) $idTurma, (int) $idDisciplina)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Associação de disciplina removida com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao remover associação de disciplina.']);
        }
    }

    public function getDisciplinasAssociatedTurmas($idDisciplina)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        // Verifica se a disciplina pertence à escola do admin
        $disciplina = $this->model->getById((int) $idDisciplina, $authData['id_escola'], $authData['userRole']);
        if (!$disciplina) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Disciplina não encontrada nesta escola.']);
            return;
        }

        // Chama a função que já existe no Model
        $turmas = $this->model->getTurmasByDisciplinaId((int) $idDisciplina);
        $this->sendResponse(200, ['success' => true, 'data' => $turmas]);
    }
}