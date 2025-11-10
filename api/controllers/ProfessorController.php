<?php
// api/controllers/ProfessorController.php

require_once __DIR__ . '/../models/Professor.php';
require_once __DIR__ . '/../models/Turma.php';

class ProfessorController
{
    private $professorModel;
    private $turmaModel;

    public function __construct()
    {
        $this->professorModel = new Professor();
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

        try {
            $professores = $this->professorModel->getAll($authData['id_escola'], $authData['userRole']);
            $totalProfessores = $this->professorModel->getTotalCount($authData['id_escola'], $authData['userRole']);

            $this->sendResponse(200, [
                'success' => true,
                'data' => $professores,
                'total' => $totalProfessores
            ]);
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor ao buscar professores: ' . $e->getMessage()]);
        }
    }

    public function getById($id)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        if (!isset($id) || !is_numeric($id)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do professor inválido.']);
            return;
        }
        $professor = $this->professorModel->getById((int) $id, $authData['id_escola'], $authData['userRole']);
        if ($professor) {
            $this->sendResponse(200, ['success' => true, 'data' => $professor]);
        } else {
            $this->sendResponse(404, ['success' => false, 'message' => 'Professor não encontrado.']);
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

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendResponse(400, ['success' => false, 'message' => 'JSON inválido fornecido.']);
            return;
        }

        if (empty($data['nome_professor']) || empty($data['cpf'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios (Nome do Professor, CPF) ausentes.']);
            return;
        }

        $result = $this->professorModel->create($data, $authData['id_escola']);

        if ($result === true) {
            $this->sendResponse(201, ['success' => true, 'message' => 'Professor criado com sucesso!']);
        } elseif ($result === 'cpf_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Já existe um professor cadastrado com este CPF nesta escola.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao criar professor.']);
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

        if (!isset($id) || !is_numeric($id) || empty($data['nome_professor']) || empty($data['cpf'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios (ID, Nome do Professor, CPF) ausentes.']);
            return;
        }
        $data['id_professor'] = (int) $id;

        $result = $this->professorModel->update($data, $authData['id_escola']);

        if ($result === true) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Professor atualizado com sucesso!']);
        } elseif ($result === 'cpf_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Já existe outro professor cadastrado com este CPF nesta escola.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao atualizar professor.']);
        }
    }

    public function delete($id)
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        if (!isset($id) || !is_numeric($id)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do professor inválido.']);
            return;
        }

        $result = $this->professorModel->delete((int) $id, $authData['id_escola']);

        if ($result === true) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Professor excluído com sucesso!']);
        } elseif ($result === 'in_use') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Não é possível excluir. O professor está associado a uma ou mais turmas.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao excluir professor ou professor não encontrado.']);
        }
    }

    // Métodos para gerenciar associações professor-turma

    public function getProfessorTurmas($idProfessor)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        // Verifica se o professor em questão pertence à escola do admin logado
        $professor = $this->professorModel->getById((int) $idProfessor, $authData['id_escola'], $authData['userRole']);
        if (!$professor) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Professor não encontrado nesta escola.']);
            return;
        }

        $turmas = $this->professorModel->getTurmasByProfessorId((int) $idProfessor);
        $this->sendResponse(200, ['success' => true, 'data' => $turmas]);
    }

    public function addTurmaToProfessor($idProfessor)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $idTurma = $data['id_turma'] ?? null;

        if (empty($idTurma) || empty($data['data_inicio_lecionar'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Dados de associação (ID Turma, Data Início) ausentes.']);
            return;
        }

        // Verifica se ambos, professor e turma, pertencem à mesma escola
        $professor = $this->professorModel->getById((int) $idProfessor, $authData['id_escola'], $authData['userRole']);
        $turma = $this->turmaModel->getById((int) $idTurma, $authData['id_escola'], $authData['userRole']);

        if (!$professor || !$turma) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Professor ou Turma não encontrados nesta escola.']);
            return;
        }

        $result = $this->professorModel->addTurmaToProfessor(
            (int) $idProfessor,
            (int) $data['id_turma'],
            $data['data_inicio_lecionar'],
            $data['data_fim_lecionar'] ?? null
        );

        if ($result === true) {
            $this->sendResponse(201, ['success' => true, 'message' => 'Turma associada ao professor com sucesso!']);
        } elseif ($result === 'already_exists') {
            $this->sendResponse(409, ['success' => false, 'message' => 'Esta turma já está associada a este professor.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao associar turma ao professor.']);
        }
    }

    public function removeTurmaFromProfessor($idProfessor, $idTurma)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        // Verifica se o professor pertence à escola do admin logado
        $professor = $this->professorModel->getById((int) $idProfessor, $authData['id_escola'], $authData['userRole']);
        if (!$professor) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Professor não encontrado nesta escola.']);
            return;
        }

        if ($this->professorModel->removeTurmaFromProfessor((int) $idProfessor, (int) $idTurma)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Associação de turma removida com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao remover associação de turma.']);
        }
    }

    /**
     * Obtém os detalhes do professor logado e suas turmas.
     * Esta função será chamada pela dashboard do professor.
     */
    public function getDashboardData($userId)
    {
        $professor = $this->professorModel->getProfessorByUserId($userId);
        if (!$professor) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Dados do professor não encontrados.']);
            return;
        }

        $turmas = $this->professorModel->getTurmasByProfessorId($professor['id_professor']);

        $this->sendResponse(200, [
            'success' => true,
            'data' => [
                'professor' => $professor,
                'turmas' => $turmas
            ]
        ]);
    }
}