<?php
// api/controllers/UsuarioController.php

require_once __DIR__ . '/../models/Usuario.php';

class UsuarioController
{
    private $usuarioModel;

    public function __construct()
    {
        $this->usuarioModel = new Usuario();
    }

    private function sendResponse($statusCode, $data)
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

    public function getAll()
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $role = $_GET['role'] ?? null;
        $nomeAluno = $_GET['nome'] ?? null;
        $ra = $_GET['ra'] ?? null;

        // <-- ALTERADO: Passa dados de autenticação para o model
        $usuarios = $this->usuarioModel->getAllUsers($authData['id_escola'], $authData['userRole'], $role, $nomeAluno, $ra);

        $this->sendResponse(200, ['success' => true, 'data' => $usuarios]);
    }

    public function getById($id)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $usuario = $this->usuarioModel->findById($id);

        // <-- ALTERADO: Verificação de segurança
        if ($usuario && ($authData['userRole'] === 'superadmin' || $usuario['id_escola'] == $authData['id_escola'])) {
            $this->sendResponse(200, ['success' => true, 'data' => $usuario]);
        } else {
            $this->sendResponse(404, ['success' => false, 'message' => 'Usuário não encontrado ou não pertence à sua escola.']);
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
        if (!isset($data['username']) || !isset($data['password']) || !isset($data['role'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Username, password e role são obrigatórios.']);
            return;
        }

        // <-- ALTERADO: Adiciona o id_escola do admin aos dados do novo usuário
        $data['id_escola'] = $authData['id_escola'];

        if ($this->usuarioModel->create($data)) {
            $this->sendResponse(201, ['success' => true, 'message' => 'Usuário criado com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao criar usuário. O username já pode existir.']);
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
        if (!isset($data['username']) || !isset($data['role'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Username e role são obrigatórios.']);
            return;
        }

        // <-- ALTERADO: Verificação de segurança antes de atualizar
        $usuarioParaAtualizar = $this->usuarioModel->findById($id);
        if (!$usuarioParaAtualizar || $usuarioParaAtualizar['id_escola'] != $authData['id_escola']) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Usuário não encontrado ou não pertence à sua escola.']);
            return;
        }

        // <-- ALTERADO: Passa o id_escola para o método do model
        if ($this->usuarioModel->updateUser($id, $data, $authData['id_escola'])) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Usuário atualizado com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao atualizar usuário.']);
        }
    }

    public function delete($id)
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        // <-- ALTERADO: Verificação de segurança antes de deletar
        $usuarioParaDeletar = $this->usuarioModel->findById($id);
        if (!$usuarioParaDeletar || $usuarioParaDeletar['id_escola'] != $authData['id_escola']) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Usuário não encontrado ou não pertence à sua escola.']);
            return;
        }

        // <-- ALTERADO: Passa o id_escola para o método do model
        if ($this->usuarioModel->deleteById($id, $authData['id_escola'])) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Usuário deletado com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao deletar usuário.']);
        }
    }

    public function resetPassword($id)
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['new_password'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'A nova senha é obrigatória.']);
            return;
        }

        // <-- ALTERADO: Verificação de segurança antes de resetar a senha
        $usuarioParaResetar = $this->usuarioModel->findById($id);
        if (!$usuarioParaResetar || $usuarioParaResetar['id_escola'] != $authData['id_escola']) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Usuário não encontrado ou não pertence à sua escola.']);
            return;
        }

        if ($this->usuarioModel->updatePassword($id, $data['new_password'])) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Senha do usuário redefinida com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao redefinir a senha.']);
        }
    }
}