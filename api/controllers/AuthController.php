<?php
// api/controllers/AuthController.php

require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Aluno.php';
require_once __DIR__ . '/../models/Contrato.php';
require_once __DIR__ . '/../../config/Auth.php';

use Firebase\JWT\JWT;

class AuthController
{
    private $usuarioModel;
    private $alunoModel;
    private $contratoModel;

    public function __construct()
    {
        $this->usuarioModel = new Usuario();
        $this->alunoModel = new Aluno();
        $this->contratoModel = new Contrato();
    }

    private function sendResponse($statusCode, $data)
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
    }

    public function login()
    {
        $data = json_decode(file_get_contents('php://input'));

        if (!isset($data->username) || !isset($data->password)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Usuário e senha são obrigatórios.']);
            return;
        }

        $usuario = $this->usuarioModel->findByUsername($data->username);

        if (!$usuario || !password_verify($data->password, $usuario['password_hash'])) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Credenciais inválidas.']);
            return;
        }

        // <-- INÍCIO DA LÓGICA DE LOGIN -->

        // 1. Forçar troca de senha se o status for 'pendente_senha'
        if (isset($usuario['status']) && $usuario['status'] === 'pendente_senha') {
            $payload_temporary = [
                'iss' => 'your_domain.com',
                'aud' => 'your_app.com',
                'iat' => time(),
                'exp' => time() + 3600, // Token temporário válido por 1 hora
                'data' => [
                    'id_usuario' => $usuario['id_usuario'],
                    'username' => $usuario['username']
                ]
            ];
            $jwt_temporary = JWT::encode($payload_temporary, JWT_SECRET_KEY, JWT_ALGORITHM);

            $this->sendResponse(200, [
                'success' => true,
                'action' => 'change_password',
                'jwt_temporary' => $jwt_temporary,
                'message' => 'Primeiro login detectado. Por favor, altere sua senha.'
            ]);
            return;
        }

        // 2. Verificar contrato pendente se a role for 'aluno_pendente'
        if ($usuario['role'] === 'aluno_pendente') {

            // =================================================================
            // ===               A CORREÇÃO ESTÁ AQUI (LINHA 74)             ===
            // =================================================================
            // O username do login (que é o ra_sef) é usado para buscar o aluno.
            $aluno = $this->alunoModel->findByRaSef($usuario['username']);
            // =================================================================

            if (!$aluno) {
                $this->sendResponse(404, ['success' => false, 'message' => 'Usuário pendente não vinculado a um cadastro de aluno.']);
                return;
            }

            $pendingContract = $this->contratoModel->findPendingByAlunoId($aluno['id_aluno']);
            if (!$pendingContract) {
                $this->sendResponse(404, ['success' => false, 'message' => 'Nenhum contrato pendente encontrado para este aluno.']);
                return;
            }

            $payload_temporary = [
                'iss' => 'your_domain.com',
                'aud' => 'your_app.com',
                'iat' => time(),
                'exp' => time() + 3600,
                'data' => ['id_usuario' => $usuario['id_usuario'], 'username' => $usuario['username'], 'role' => 'aluno_pendente']
            ];
            $jwt_temporary = JWT::encode($payload_temporary, JWT_SECRET_KEY, JWT_ALGORITHM);

            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Contrato pendente encontrado.',
                'contract_pending' => true,
                'jwt_temporary' => $jwt_temporary,
                'contract_id' => $pendingContract['id_contrato'],
                'contract_path' => $pendingContract['caminho_pdf']
            ]);
            return;
        }

        // 3. LOGIN NORMAL: Gerar token JWT com todos os dados necessários
        $payload = [
            'iss' => 'your_domain.com',
            'aud' => 'your_app.com',
            'iat' => time(),
            'exp' => time() + JWT_EXPIRATION_TIME,
            'data' => [
                'id_usuario' => $usuario['id_usuario'],
                'username' => $usuario['username'],
                'role' => $usuario['role'],
                'id_escola' => $usuario['id_escola']
            ]
        ];

        $jwt = JWT::encode($payload, JWT_SECRET_KEY, JWT_ALGORITHM);

        $this->sendResponse(200, [
            'success' => true,
            'message' => 'Login bem-sucedido!',
            'jwt' => $jwt,
            'user_role' => $usuario['role']
        ]);
    }

    /**
     * Processa a primeira alteração de senha de um usuário.
     */
    public function firstPasswordChange()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $newPassword = $data['new_password'] ?? null;
        $addemail = $data['email'] ?? null;

        if (!$newPassword) {
            $this->sendResponse(400, ['success' => false, 'message' => 'A nova senha é obrigatória.']);
            return;
        }

        // <-- ALTERADO: Validação de e-mail é necessária
        if (!$addemail) {
            $this->sendResponse(400, ['success' => false, 'message' => 'O e-mail é obrigatório.']);
            return;
        }

        $idUsuario = getUserIdFromToken();
        if (!$idUsuario) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Token inválido ou não fornecido.']);
            return;
        }



        if ($this->usuarioModel->updatePasswordAndStatus($idUsuario, $newPassword, $addemail)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Senha alterada com sucesso! Você já pode fazer o login.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao alterar a senha.']);
        }
    }

    /**
     * Permite que um usuário já autenticado altere sua própria senha.
     */
    public function changePassword()
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['current_password']) || !isset($data['new_password'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios ausentes.']);
            return;
        }

        $userData = $GLOBALS['user_data'] ?? null;
        if (!$userData || !isset($userData['username'])) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Usuário não autenticado ou token inválido.']);
            return;
        }

        $usuario = $this->usuarioModel->findByUsername($userData['username']);
        if (!$usuario || !$this->usuarioModel->verifyPassword($data['current_password'], $usuario['password_hash'])) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Senha atual incorreta.']);
            return;
        }

        if ($this->usuarioModel->updatePassword($usuario['id_usuario'], $data['new_password'])) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Senha alterada com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao atualizar a senha.']);
        }
    }

    private function sendToN8n($webhookUrl, $data)
    {
        $options = [
            'http' => [
                'header' => "Content-type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($data),
                'ignore_errors' => true
            ],
        ];
        $context = stream_context_create($options);
        $result = file_get_contents($webhookUrl, false, $context);

        if ($result === FALSE || !str_contains($http_response_header[0], '200')) {
            error_log("Falha ao enviar webhook para n8n: " . $http_response_header[0]);
            return false;
        }
        return true;
    }


    /**
     * Inicia o processo de "Esqueci a Senha".
     */
    public function requestResetByUsername()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $username = $data['username'] ?? null; // <-- ALTERADO: Recebe username (RA_SEF)

        if (!$username) {
            $this->sendResponse(400, ['success' => false, 'message' => 'O Usuário (RA) é obrigatório.']);
            return;
        }

        $usuario = $this->usuarioModel->findByUsername($username); // <-- ALTERADO: Busca por username

        // Verifica se o usuário existe E se ele tem um e-mail cadastrado
        if (!$usuario || empty($usuario['email'])) {
            $this->sendResponse(404, [
                'success' => false,
                'message' => 'Usuário não encontrado ou sem e-mail cadastrado para recuperação.'
            ]);
            return;
        }

        // Se encontrou, retorna o e-mail mascarado
        $maskedEmail = $this->maskEmail($usuario['email']);
        $this->sendResponse(200, [
            'success' => true,
            'message' => 'Encontramos um e-mail. Confirme-o para continuar.',
            'masked_email' => $maskedEmail
        ]);
    }

    /**
     * Completa o processo de reset de senha usando o token.
     */
    public function resetPassword()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $token = $data['token'] ?? null;
        $newPassword = $data['new_password'] ?? null;

        if (!$token || !$newPassword) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Token e nova senha são obrigatórios.']);
            return;
        }

        if ($this->usuarioModel->resetPasswordByToken($token, $newPassword)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Senha redefinida com sucesso! Você já pode fazer o login.']);
        } else {
            $this->sendResponse(400, ['success' => false, 'message' => 'Token inválido, expirado ou senha incorreta.']);
        }
    }



    private function maskEmail($email)
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            list($user, $domain) = explode('@', $email);
            list($domain_name, $domain_tld) = explode('.', $domain);

            // Mascara o usuário: exibe os 2 primeiros e o último
            $user_masked = substr($user, 0, 2) . str_repeat('*', max(1, strlen($user) - 3)) . substr($user, -1);

            // Mascara o domínio: exibe o primeiro e o último
            $domain_masked = substr($domain_name, 0, 1) . str_repeat('*', max(1, strlen($domain_name) - 2)) . substr($domain_name, -1);

            return $user_masked . '@' . $domain_masked . '.' . $domain_tld;
        }
        return "E-mail inválido";
    }

    public function confirmEmailAndSendCode()
    {
        $data = json_decode(file_get_contents("php://input"), true);
        $username = $data['username'] ?? null;
        $full_email = $data['full_email'] ?? null;

        if (!$username || !$full_email) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Dados incompletos.']);
            return;
        }

        $usuario = $this->usuarioModel->findByUsername($username);

        if (!$usuario) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Usuário não encontrado.']);
            return;
        }

        // Compara o e-mail digitado com o e-mail do banco (ignorando maiúsculas/minúsculas)
        if (strtolower(trim($full_email)) !== strtolower(trim($usuario['email']))) {
            $this->sendResponse(400, [
                'success' => false,
                'message' => 'O e-mail digitado não confere com o e-mail cadastrado para este usuário.'
            ]);
            return;
        }

        // E-mail BATEU! Agora sim, geramos o token e enviamos o e-mail.
        $token = $this->usuarioModel->setResetToken($usuario['id_usuario']);

        if ($token) {
            $n8nWebhookUrl = 'https://sistema-crescer-n8n.vuvd0x.easypanel.host/webhook/reset-password';
            $payload = [
                'email' => $usuario['email'],
                'code' => $token
            ];
            $this->sendToN8n($n8nWebhookUrl, $payload);

            $this->sendResponse(200, [
                'success' => true,
                'message' => 'E-mail confirmado! Um código de recuperação foi enviado.'
            ]);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao gerar o código de recuperação.']);
        }
    }
}