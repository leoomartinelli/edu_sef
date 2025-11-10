<?php
// api/controllers/PendenciaController.php

require_once __DIR__ . '/../models/Responsavel.php';
require_once __DIR__ . '/../models/Pendencia.php';

class PendenciaController
{
    private $responsavelModel;
    private $pendenciaModel;

    public function __construct()
    {
        $this->responsavelModel = new Responsavel();
        $this->pendenciaModel = new Pendencia();
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

    // MÉTODO CORRIGIDO
    public function consultarPendenciasPorCpf()
    {
        // Pega os dados enviados via POST
        $data = json_decode(file_get_contents('php://input'), true);
        $cpf = $data['cpf'] ?? null;

        // Validação básica do CPF
        if (!$cpf) {
            $this->sendResponse(400, ['success' => false, 'message' => 'CPF não foi enviado.']);
            return;
        }

        $cpfLimpo = preg_replace('/\D/', '', $cpf);

        // 1. Busca o responsável em TODAS as escolas. Não passamos o id_escola.
        // Estamos usando o método findByCpf que ajustamos antes.
        $responsavel = $this->responsavelModel->findByCpf($cpfLimpo);

        // Se encontrou o responsável em PELO MENOS UMA escola...
        if ($responsavel) {
            // 2. Busca as pendências dele em TODAS as escolas usando o novo método.
            $pendencias = $this->pendenciaModel->findPendenciasByResponsavelId($responsavel['id_responsavel']);

            $valorTotal = array_reduce($pendencias, fn($sum, $item) => $sum + ($item['valor'] ?? 0), 0);

            $this->sendResponse(200, [
                'success' => true,
                'status' => count($pendencias) > 0 ? "pendências encontradas" : "nenhuma pendência apontada",
                'total_pendencias' => count($pendencias),
                'valor_total' => $valorTotal,
                'nome' => $responsavel['nome'],
                'cpf' => $responsavel['cpf'],
                // 'results' agora contém as pendências de todas as escolas
                'results' => $pendencias
            ]);
        } else {
            // Se não encontrou o CPF em NENHUMA escola no sistema.
            $this->sendResponse(404, ['success' => false, 'message' => 'Responsável não encontrado em nenhuma escola no sistema.']);
        }
    }

    public function salvarPendencias()
    {
        $authData = $this->getAuthData();
        if (!$authData || !$authData['id_escola']) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['cpf']) || !isset($data['nome']) || !isset($data['pendencias'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Dados de pendências incompletos.']);
            return;
        }

        $cpf = $data['cpf'];
        $nome = $data['nome'];
        $pendencias = $data['pendencias'];
        $cep = $data['cep'] ?? null;

        $responsavel = $this->responsavelModel->findByCpf($cpf, $authData['id_escola']);

        if (!$responsavel) {
            $responsavel_data = [
                'nome' => $nome,
                'cpf' => $cpf,
                'cep' => $cep,
                'id_escola' => $authData['id_escola']
            ];
            $id_responsavel = $this->responsavelModel->create($responsavel_data);
            if (!$id_responsavel) {
                $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao salvar o responsável.']);
                return;
            }
        } else {
            $id_responsavel = $responsavel['id_responsavel'];
        }

        $pendencias_salvas_com_sucesso = 0;
        foreach ($pendencias as $item) {
            $item['id_responsavel'] = $id_responsavel;
            $item['id_escola'] = $authData['id_escola'];

            $result = $this->pendenciaModel->create($item);
            if ($result === true) {
                $pendencias_salvas_com_sucesso++;
            }
        }

        $this->sendResponse(200, ['success' => true, 'message' => "$pendencias_salvas_com_sucesso pendências salvas ou atualizadas com sucesso."]);
    }



}