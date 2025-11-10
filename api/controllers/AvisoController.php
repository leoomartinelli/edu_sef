<?php
// api/controllers/AvisoController.php

require_once __DIR__ . '/../models/Aviso.php';
require_once __DIR__ . '/../models/Professor.php';

class AvisoController
{
    public function getAll()
    {
        $userData = $GLOBALS['user_data'] ?? null;
        // <-- ALTERADO: Pega o id_escola do token
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;

        if (!$userData || !$id_escola) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Usuário não autenticado ou não associado a uma escola.']);
            return;
        }

        $avisoModel = new Aviso();
        // <-- ALTERADO: Passa o id_escola para o model
        $avisos = $avisoModel->getAllByUserData($userData, $id_escola);

        echo json_encode(['success' => true, 'data' => $avisos]);
    }

    public function create()
    {
        $userData = $GLOBALS['user_data'] ?? null;
        $role = $userData['role'] ?? null;
        $idUsuario = $userData['id_usuario'] ?? null;
        // <-- ALTERADO: Pega o id_escola do token
        $id_escola = $userData['id_escola'] ?? null;

        if (($role !== 'admin' && $role !== 'professor') || !$id_escola) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acesso negado ou usuário sem escola definida.']);
            return;
        }

        $data = json_decode(file_get_contents("php://input"));

        if (empty($data->titulo) || empty($data->mensagem)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Título e mensagem são obrigatórios.']);
            return;
        }

        $idTurma = isset($data->id_turma) && !empty($data->id_turma) ? $data->id_turma : null;

        // VALIDAÇÃO PARA PROFESSOR (nenhuma mudança necessária aqui)
        if ($role === 'professor') {
            if ($idTurma) {
                $professorModel = new Professor();
                $professor = $professorModel->getByUserId($idUsuario);
                $turmasDoProfessor = $professorModel->getProfessorTurmas($professor['id_professor']);
                $idsTurmas = array_column($turmasDoProfessor, 'id_turma');

                if (!in_array($idTurma, $idsTurmas)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Você só pode enviar avisos para turmas que leciona.']);
                    return;
                }
            } else {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Professores não podem enviar avisos globais. Selecione uma turma.']);
                return;
            }
        }

        $avisoModel = new Aviso();
        // <-- ALTERADO: Passa o id_escola para o model
        $newAvisoId = $avisoModel->create($data->titulo, $data->mensagem, $idUsuario, $id_escola, $idTurma);

        if ($newAvisoId) {
            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Aviso criado com sucesso.', 'data' => ['id_aviso' => $newAvisoId]]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro ao criar aviso.']);
        }
    }

    public function delete($id)
    {
        $userData = $GLOBALS['user_data'] ?? null;
        // <-- ALTERADO: Pega o id_escola do token
        $id_escola = $userData['id_escola'] ?? null;

        if (($userData['role'] ?? '') !== 'admin' || !$id_escola) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acesso negado. Apenas administradores de uma escola podem excluir avisos.']);
            return;
        }

        $avisoModel = new Aviso();
        // <-- ALTERADO: Passa o id e o id_escola para segurança
        if ($avisoModel->delete($id, $id_escola)) {
            echo json_encode(['success' => true, 'message' => 'Aviso excluído com sucesso.']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro ao excluir aviso. O aviso pode não existir ou não pertencer à sua escola.']);
        }
    }
}