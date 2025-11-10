<?php
// api/controllers/ConteudoProgramaticoController.php

require_once __DIR__ . '/../models/ConteudoProgramatico.php';
require_once __DIR__ . '/../models/Professor.php'; // Para pegar o ID do professor logado

class ConteudoProgramaticoController
{
    private $conteudoModel;
    private $professorModel;
    private $turmaModel;

    public function __construct()
    {
        $this->conteudoModel = new ConteudoProgramatico();
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
        $id_usuario = $GLOBALS['user_data']['id_usuario'] ?? null;

        if ($userRole !== 'superadmin' && !$id_escola)
            return null;

        return ['id_escola' => $id_escola, 'userRole' => $userRole, 'id_usuario' => $id_usuario];
    }

    // Admin cria uma nova meta de conteúdo
    public function create($idTurma)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $data['id_turma'] = (int)$idTurma;

        // Validação básica
        if (empty($data['titulo']) || empty($data['bimestre']) || empty($data['data_inicio_prevista']) || empty($data['data_fim_prevista']) || !isset($data['meta_pagina_inicio']) || !isset($data['meta_pagina_fim'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios ausentes.']);
            return;
        }

        if ($this->conteudoModel->create($data, $authData['id_escola'])) {
            $this->sendResponse(201, ['success' => true, 'message' => 'Conteúdo programático criado com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao criar conteúdo programático.']);
        }
    }

    // Busca todos os conteúdos de uma turma
    public function getByTurma($idTurma)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $conteudos = $this->conteudoModel->getByTurma((int) $idTurma, $authData['id_escola']);
        $this->sendResponse(200, ['success' => true, 'data' => $conteudos]);
    }

    // Professor lança seu progresso
    public function addProgresso($idConteudo)
    {
        $authData = $this->getAuthData();
        if (!$authData) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['data_aula']) || !isset($data['paginas_concluidas_inicio']) || !isset($data['paginas_concluidas_fim'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios (data, páginas) ausentes.']);
            return;
        }

        $id_professor_a_registrar = null;

        // 1. Tenta encontrar um perfil de professor para o usuário logado
        $professor_logado = $this->professorModel->getByUserId($authData['id_usuario']);
        if ($professor_logado) {
            $id_professor_a_registrar = $professor_logado['id_professor'];
        }
        // 2. Se não encontrou e o usuário é ADMIN, aplica a nova lógica
        elseif ($authData['userRole'] === 'admin') {
            // Pega os detalhes do conteúdo para descobrir a turma
            $conteudo = $this->conteudoModel->getById((int) $idConteudo, $authData['id_escola']);
            if (!$conteudo) {
                $this->sendResponse(404, ['success' => false, 'message' => 'Conteúdo programático não encontrado.']);
                return;
            }

            // Busca os professores daquela turma
            $professores_da_turma = $this->turmaModel->getProfessoresByTurmaId($conteudo['id_turma']);

            if (!empty($professores_da_turma)) {
                // Usa o ID do primeiro professor encontrado para a turma
                $id_professor_a_registrar = $professores_da_turma[0]['id_professor'];
            }
        }

        // 3. Verifica se, após toda a lógica, um ID de professor foi encontrado
        if (is_null($id_professor_a_registrar)) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Ação negada. Não foi possível encontrar um professor responsável para associar a este lançamento. Verifique se a turma tem professores vinculados.']);
            return;
        }

        $data['id_conteudo'] = (int) $idConteudo;

        // 4. Salva o progresso usando o ID do professor encontrado
        if ($this->conteudoModel->addProgresso($data, $id_professor_a_registrar)) {
            $this->sendResponse(201, ['success' => true, 'message' => 'Progresso lançado com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao lançar progresso.']);
        }
    }

    // Pega os dados formatados para o gráfico
    public function getGraficoData($idConteudo)
    {
        $authData = $this->getAuthData();
        // Valida se o conteúdo pertence à escola do usuário
        $conteudo = $this->conteudoModel->getById((int) $idConteudo, $authData['id_escola']);
        if (!$authData || !$conteudo) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Conteúdo programático não encontrado.']);
            return;
        }

        $data = $this->conteudoModel->getProgressoParaGrafico((int) $idConteudo);

        if ($data) {
            $this->sendResponse(200, ['success' => true, 'data' => $data]);
        } else {
            $this->sendResponse(404, ['success' => false, 'message' => 'Dados para o gráfico não encontrados.']);
        }
    }

    
}