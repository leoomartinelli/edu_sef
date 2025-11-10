<?php
// api/controllers/BoletimController.php

require_once __DIR__ . '/../models/Boletim.php';
require_once __DIR__ . '/../models/Aluno.php';
require_once __DIR__ . '/../models/Disciplina.php';

class BoletimController
{
    private $boletimModel;
    private $alunoModel;
    private $disciplinaModel;

    public function __construct()
    {
        $this->boletimModel = new Boletim();
        $this->alunoModel = new Aluno();
        $this->disciplinaModel = new Disciplina();
    }

    private function sendResponse($statusCode, $data)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code($statusCode);
        }
        echo json_encode($data);
    }

    public function getBoletimAluno()
    {
        // <-- ALTERADO: Pega id_escola do token
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Usuário não associado a uma escola.']);
            return;
        }

        $idAluno = isset($_GET['id_aluno']) ? (int) $_GET['id_aluno'] : null;
        $anoLetivo = isset($_GET['ano_letivo']) ? (int) $_GET['ano_letivo'] : null;

        if (!$idAluno || !$anoLetivo) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do aluno e ano letivo são obrigatórios.']);
            return;
        }

        // <-- ALTERADO: Passa id_escola para o model
        $boletim = $this->boletimModel->getBoletimByAlunoAndAno($idAluno, $anoLetivo, $id_escola);
        $this->sendResponse(200, ['success' => true, 'data' => $boletim]);
    }

    public function saveBoletimEntry()
    {
        // <-- ALTERADO: Pega id_escola do token
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Usuário não associado a uma escola.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendResponse(400, ['success' => false, 'message' => 'JSON inválido fornecido.']);
            return;
        }

        if (empty($data['id_aluno']) || empty($data['id_turma']) || empty($data['ano_letivo']) || empty($data['id_disciplina'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios (id_aluno, id_turma, ano_letivo, id_disciplina) ausentes.']);
            return;
        }

        // ... (lógica de conversão de dados) ...

        // <-- ALTERADO: Passa id_escola para o model
        if ($this->boletimModel->saveBoletimEntry($data, $id_escola)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Boletim salvo com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao salvar boletim.']);
        }
    }

    public function deleteBoletimEntry($idBoletim)
    {
        // <-- ALTERADO: Pega id_escola do token
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Usuário não associado a uma escola.']);
            return;
        }

        if (!isset($idBoletim) || !is_numeric($idBoletim)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do boletim inválido.']);
            return;
        }

        // <-- ALTERADO: Passa id_escola para o model para segurança
        if ($this->boletimModel->delete((int) $idBoletim, $id_escola)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Entrada de boletim excluída com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao excluir entrada de boletim.']);
        }
    }

    public function getBoletimManagementData($idTurma)
    {
        // <-- ALTERADO: Pega id_escola do token
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Usuário não associado a uma escola.']);
            return;
        }

        if (!isset($idTurma) || !is_numeric($idTurma)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID da turma inválido.']);
            return;
        }

        // Lembre-se que os models Aluno e Disciplina também foram/precisam ser alterados
        // para filtrar por id_escola, então passamos o parâmetro para eles também.
        $alunos = $this->alunoModel->getAlunosByTurmaId((int) $idTurma, $id_escola);
        $disciplinas = $this->disciplinaModel->getDisciplinasByTurmaId((int) $idTurma, $id_escola); // Supondo que você também alterará Disciplina.php

        if ($alunos !== null && $disciplinas !== null) {
            $this->sendResponse(200, [
                'success' => true,
                'data' => [
                    'alunos' => $alunos,
                    'disciplinas' => $disciplinas
                ]
            ]);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao carregar dados para gerenciamento de boletim.']);
        }
    }
}