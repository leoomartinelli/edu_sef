<?php
// api/controllers/MatriculaController.php
require_once __DIR__ . '/../models/MatriculaPendente.php';
require_once __DIR__ . '/../models/Aluno.php';
require_once __DIR__ . '/../models/Turma.php';
require_once __DIR__ . '/../models/Contrato.php';
require_once __DIR__ . '/../models/Mensalidade.php';
require_once __DIR__ . '/../models/Responsavel.php';
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../models/Material.php';

use Dompdf\Dompdf;
use Dompdf\Options;

class MatriculaController
{
    private $model;
    private $alunoModel;
    private $turmaModel;
    private $contratoModel;
    private $mensalidadeModel;
    private $responsavelModel;
    private $materialModel;

    // URL do seu Webhook N8N para envio do link
    private $webhookUrl = 'https://sistema-crescer-n8n.vuvd0x.easypanel.host/webhook/envio-matricula';
    // URL base do seu formulário (onde o formulario_responsavel.html está hospedado)
    private $baseUrlFormulario; // Substitua pelo seu domínio/caminho real

    public function __construct()
    {
        $this->model = new MatriculaPendente();
        $this->alunoModel = new Aluno();
        $this->turmaModel = new Turma();
        $this->contratoModel = new Contrato();
        $this->mensalidadeModel = new Mensalidade();
        $this->responsavelModel = new Responsavel();
        $this->materialModel = new Material();

        // --- CÓDIGO ADICIONADO PARA PEGAR A URL AUTOMATICAMENTE ---

        // 1. Define o protocolo (http ou https)
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";

        // 2. Pega o host (ex: localhost, seusite.com.br)
        $host = $_SERVER['HTTP_HOST'];

        // 3. Monta a URL base dinamicamente
        //    (Mantemos /public pois é uma pasta fixa do seu projeto)
        $this->baseUrlFormulario = $protocol . "://" . $host . "/public";

        // --- FIM DO CÓDIGO ADICIONADO ---
    }

    private function sendResponse($statusCode, $data)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code($statusCode);
        }
        echo json_encode($data);
    }

    /**
     * PASSO 2: Admin inicia o processo e envia o webhook
     */
    public function iniciarProcesso()
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Escola não identificada.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        // Validação básica dos campos do admin
        if (
            empty($data['admin_resp_nome']) || empty($data['admin_resp_cpf']) || empty($data['admin_resp_celular']) ||
            empty($data['admin_ano_inicio']) || empty($data['admin_id_turma']) || empty($data['prazo'])
        ) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios (Nome, CPF, Celular, Ano Início, Turma e Prazo) não foram preenchidos.']);
            return;
        }

        try {
            // 1. Preparar dados para o banco
            $token = bin2hex(random_bytes(32)); // Gera um token de segurança
            $data['id_escola'] = $id_escola;
            $data['token'] = $token;
            $data['admin_bolsista'] = $data['admin_bolsista'] ?? false;

            // 2. Salvar no banco de dados
            $matriculaId = $this->model->create($data);
            if (!$matriculaId) {
                throw new Exception('Falha ao salvar o processo no banco de dados.');
            }

            // 3. Preparar dados para o Webhook N8N
            $linkFormulario = $this->baseUrlFormulario . '/formulario_responsavel.html?token=' . $token;

            $payloadN8N = [
                'nome_responsavel' => $data['admin_resp_nome'],
                'celular_responsavel' => $data['admin_resp_celular'],
                'link_formulario' => $linkFormulario,
                'prazo' => $data['prazo']
            ];

            // 4. Enviar para o Webhook (N8N)
            $this->callWebhook($this->webhookUrl, $payloadN8N);

            // 5. Responder ao Admin
            $this->sendResponse(201, ['success' => true, 'message' => 'Processo iniciado e link enviado ao responsável com sucesso!']);

        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao iniciar processo: ' . $e->getMessage()]);
        }
    }

    /**
     * PASSO 3: Responsável preenche o formulário (Rota Pública)
     */
    public function preencherFormulario()
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $token = $data['token'] ?? null;

        if (empty($token)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Token de validação ausente.']);
            return;
        }

        try {
            // 1. Validar o token e o status
            $matricula = $this->model->findByToken($token);
            if (!$matricula) {
                $this->sendResponse(404, ['success' => false, 'message' => 'Formulário não encontrado ou já preenchido. Solicite um novo link.']);
                return;
            }

            // 2. Validar prazo
            if (strtotime($matricula['prazo']) < strtotime(date('Y-m-d'))) {
                $this->sendResponse(410, ['success' => false, 'message' => 'O prazo para preenchimento deste formulário expirou.']);
                return;
            }

            // 3. Validação dos dados do formulário
            if (empty($data['aluno_nome']) || empty($data['aluno_nascimento'])) {
                $this->sendResponse(400, ['success' => false, 'message' => 'Nome e Data de Nascimento do Aluno são obrigatórios.']);
                return;
            }

            // 4. Atualizar o registro no banco
            if ($this->model->updateFromForm($token, $data)) {

                // ====================================================================
                // ===               INÍCIO DA MUDANÇA (WEBHOOK)                    ===
                // ====================================================================
                // Chama o novo webhook de confirmação
                try {
                    $payloadConfirmacao = [
                        // Pega os dados que o ADMIN digitou (guardados no banco)
                        'nome_responsavel' => $matricula['admin_resp_nome'],
                        'celular_responsavel' => $matricula['admin_resp_celular']
                    ];
                    $webhookConfirmacaoUrl = 'https://sistema-crescer-n8n.vuvd0x.easypanel.host/webhook/confirmacao-resposta';

                    // Chama a função helper que já existe neste controller
                    $this->callWebhook($webhookConfirmacaoUrl, $payloadConfirmacao);

                } catch (Exception $webhookError) {
                    // Se o webhook falhar, apenas loga o erro. 
                    // Não para a execução, pois o formulário JÁ FOI SALVO.
                    error_log("Webhook de confirmação de resposta falhou: " . $webhookError->getMessage());
                }
                // ====================================================================
                // ===                FIM DA MUDANÇA (WEBHOOK)                      ===
                // ====================================================================

                $this->sendResponse(200, ['success' => true, 'message' => 'Dados enviados com sucesso!']);
            } else {
                throw new Exception('Não foi possível salvar os dados.');
            }

        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao processar formulário: ' . $e->getMessage()]);
        }
    }

    /**
     * PASSO 4: Admin lista os envios pendentes
     */
    public function getMatriculasPendentes()
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        try {
            $matriculas = $this->model->getAllByEscola($id_escola);
            $this->sendResponse(200, ['success' => true, 'data' => $matriculas]);
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao buscar matrículas: ' . $e->getMessage()]);
        }
    }

    /**
     * PASSO 5: Admin vê detalhes de um envio
     */
    public function getDetalheMatricula($id)
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        try {
            $matricula = $this->model->getById($id, $id_escola);
            if ($matricula) {
                $this->sendResponse(200, ['success' => true, 'data' => $matricula]);
            } else {
                $this->sendResponse(404, ['success' => false, 'message' => 'Registro não encontrado.']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao buscar detalhes: ' . $e->getMessage()]);
        }
    }

    /**
     * PASSO 5: Admin exclui um processo
     */
    public function excluirProcesso($id)
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        try {
            if ($this->model->deleteById($id, $id_escola)) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Processo excluído com sucesso.']);
            } else {
                $this->sendResponse(404, ['success' => false, 'message' => 'Não foi possível excluir. Registro não encontrado.']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao excluir: ' . $e->getMessage()]);
        }
    }

    /**
     * PASSO 5: Admin ACEITA a matrícula
     * Isso transforma a matrícula pendente em um Aluno + Responsável + Contrato + Mensalidades reais
     */
    public function aceitarMatricula($id)
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        $userRole = $GLOBALS['user_data']['role'] ?? null; // Pega o userRole para passar adiante

        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $db = null; // Inicializa a variável do banco

        try {
            $db = (new Database())->getConnection();
            $db->beginTransaction();

            // 1. Buscar todos os dados da matrícula pendente
            $matricula = $this->model->getById($id, $id_escola);
            if (!$matricula || $matricula['status'] !== 'Preenchido') {
                throw new Exception('Matrícula não encontrada ou ainda não preenchida pelo responsável.');
            }

            // 2. Criar/Encontrar o Responsável Financeiro (reutilizando seu Model)
            $dadosResponsavel = [
                'nome' => $matricula['admin_resp_nome'],
                'cpf' => $matricula['admin_resp_cpf'],
                'celular' => $matricula['admin_resp_celular'],
                'email' => $matricula['resp_fin_email'],
                'data_nascimento' => $matricula['resp_fin_nascimento'],
                'cep' => $matricula['resp_cep'],
                'id_escola' => $id_escola
            ];
            $id_responsavel = $this->responsavelModel->findOrCreate($dadosResponsavel);
            if (!$id_responsavel) {
                throw new Exception('Falha ao processar o responsável financeiro.');
            }

            // 3. Preparar dados para criar o Aluno (reutilizando seu Model)
            $dadosAluno = [
                // Dados do Aluno
                'nome_aluno' => $matricula['resp_aluno_nome'],
                'data_nascimento' => $matricula['resp_aluno_nascimento'],
                'rg_aluno' => $matricula['resp_aluno_rg'],
                'cpf_aluno' => $matricula['resp_aluno_cpf'],
                'ra_sef' => null, // O create() do AlunoModel vai gerar
                'ra' => null,
                'idade' => null, // O create() poderia calcular, se necessário

                // Dados da Matrícula
                'id_turma' => $matricula['admin_id_turma'],
                'periodo' => null, // Opcional, pode vir da Turma
                'ano_inicio' => $matricula['admin_ano_inicio'],
                'data_inicio' => date('Y-m-d'), // Opcional: data de hoje

                // Endereço
                'cep' => $matricula['resp_cep'],
                'endereco' => $matricula['resp_endereco'],
                'bairro' => $matricula['resp_bairro'],
                'cidade' => $matricula['resp_cidade'],
                'estado' => $matricula['resp_estado'],
                'complemento' => $matricula['resp_complemento'],

                // Resp. Pedagógico
                'nome_resp_pedagogico' => $matricula['resp_ped_nome'],
                'cpf_resp_pedagogico' => $matricula['resp_ped_cpf'],
                'celular_resp_pedagogico' => $matricula['resp_ped_celular'],
                'email_resp_pedagogico' => $matricula['resp_ped_email'],
                'data_nascimento_resp_pedagogico' => $matricula['resp_ped_nascimento'],

                // IDs
                'id_resp_financeiro' => $id_responsavel,
                'id_escola' => $id_escola,
            ];

            // 4. Criar o Aluno (isso também cria o Usuário)
            $resultAluno = $this->alunoModel->create($dadosAluno, $id_escola);
            if (is_string($resultAluno)) { // Se for string, é uma mensagem de erro
                throw new Exception($resultAluno);
            }
            $id_aluno = $resultAluno['id_aluno'];
            $ra_sef_gerado = $resultAluno['ra_sef'];

            // 5. Preparar dados para Contrato e Mensalidades
            $dadosParaContrato = $dadosAluno; // Reutiliza os dados do aluno
            $dadosParaContrato['matricula_pendente'] = $matricula; // Passa os dados financeiros originais
            $dadosParaContrato['ra_sef'] = $ra_sef_gerado; // Adiciona o RA gerado

            // Adiciona dados financeiros ao array para gerar PDF e Mensalidades
            $dadosParaContrato['valor_anuidade_total'] = $matricula['admin_anuidade'];
            $dadosParaContrato['valor_matricula'] = $matricula['admin_matricula'];
            $dadosParaContrato['dia_vencimento_mensalidades'] = $matricula['admin_vencimento'];
            $dadosParaContrato['nome_resp_financeiro'] = $matricula['admin_resp_nome'];
            $dadosParaContrato['cpf_resp_financeiro'] = $matricula['admin_resp_cpf'];
            $dadosParaContrato['celular_resp_financeiro'] = $matricula['admin_resp_celular'];
            $dadosParaContrato['valor_anuidade_material'] = $matricula['admin_material'];
            $dadosParaContrato['numero_parcelas_material'] = $matricula['admin_parcelas_material'];


            // 6. Gerar Contrato (reutilizando sua lógica)
            // CORREÇÃO: Passar $id_escola e $userRole
            $caminho_pdf = $this->gerarContratoPDF($id_aluno, $dadosParaContrato, $id_escola, $userRole);
            if ($caminho_pdf) {
                $this->contratoModel->create(['id_aluno' => $id_aluno, 'caminho_pdf' => $caminho_pdf], $id_escola);
            } else {
                throw new Exception("Aluno criado (RA: {$ra_sef_gerado}), mas falha ao gerar o PDF do contrato.");
            }

            // =================================================================
            // ===               INÍCIO DA CORREÇÃO (MENSALIDADES)           ===
            // =================================================================

            // 7. Gerar Mensalidades (lógica ATUALIZADA)
            $valorAnuidadeTotal = (float) ($matricula['admin_anuidade'] ?? 0);
            $valorMatricula = (float) ($matricula['admin_matricula'] ?? 0);
            $diaVencimentoMensalidade = $matricula['admin_vencimento'] ?? 10;
            $valorMaterial = (float) ($matricula['admin_material'] ?? 0);
            $parcelasMaterial = (int) ($matricula['admin_parcelas_material'] ?? 1);

            // Pega o ano do formulário (ex: 2026)
            $anoLetivo = (int) ($matricula['admin_ano_inicio'] ?? date('Y'));

            // SIMPLIFICADO: Sempre começa do Mês 1 (Janeiro) do Ano Letivo
            $mesInicial = 1;
            $totalParcelasAnuidade = 12;

            // --- GERAÇÃO DA ANUIDADE (MENSALIDADES) ---
            if ($valorAnuidadeTotal > 0 && !$matricula['admin_bolsista']) {
                // 1. Cria a Matrícula
                $this->mensalidadeModel->create([
                    'id_aluno' => $id_aluno,
                    'valor_mensalidade' => $valorMatricula,
                    'data_vencimento' => (new DateTime())->modify('+10 days')->format('Y-m-d'),
                    'descricao' => 'Matrícula'
                ], $id_escola);

                // 2. Calcula e cria as mensalidades
                if ($totalParcelasAnuidade > 0 && ($valorAnuidadeTotal > $valorMatricula)) {
                    $valorMensalidadeCalculada = ($valorAnuidadeTotal - $valorMatricula) / 12;

                    for ($i = 0; $i < $totalParcelasAnuidade; $i++) {
                        $mesDaParcela = $mesInicial + $i;

                        // Usa $anoLetivo (o ano do formulário) para criar a data
                        $dataVencimento = new DateTime("{$anoLetivo}-{$mesDaParcela}-{$diaVencimentoMensalidade}");

                        $this->mensalidadeModel->create([
                            'id_aluno' => $id_aluno,
                            'valor_mensalidade' => $valorMensalidadeCalculada,
                            'data_vencimento' => $dataVencimento->format('Y-m-d'),
                            'descricao' => "Mensalidade " . ($mesDaParcela) . "/" . $anoLetivo
                        ], $id_escola);
                    }
                }
            }

            // --- GERAÇÃO DO MATERIAL DIDÁTICO ---
            if ($valorMaterial > 0 && $parcelasMaterial > 0 && !$matricula['admin_bolsista']) {

                // Adiciona lógica de arredondamento para a última parcela
                $valorParcelaMaterial = round($valorMaterial / $parcelasMaterial, 2);
                $valorTotalCalculado = $valorParcelaMaterial * ($parcelasMaterial - 1);
                $valorUltimaParcela = $valorMaterial - $valorTotalCalculado;

                // $anoLetivo and $mesInicial já foram definidos acima (Janeiro)

                for ($i = 0; $i < $parcelasMaterial; $i++) {
                    $mesDaParcela = $mesInicial + $i;
                    if ($mesDaParcela <= 12) {

                        $dataVencimento = new DateTime("{$anoLetivo}-{$mesDaParcela}-{$diaVencimentoMensalidade}");
                        $valorDaParcelaAtual = ($i == $parcelasMaterial - 1) ? $valorUltimaParcela : $valorParcelaMaterial;

                        // vvvvvvvvvvv MUDANÇA PRINCIPAL vvvvvvvvvvv
                        // Agora usa o materialModel e 'valor_parcela'
                        $this->materialModel->create([
                            'id_aluno' => $id_aluno,
                            'valor_parcela' => $valorDaParcelaAtual,
                            'data_vencimento' => $dataVencimento->format('Y-m-d'),
                            'descricao' => "Material Didático " . ($i + 1) . "/{$parcelasMaterial}"
                        ], $id_escola);
                        // ^^^^^^^^^^^ MUDANÇA PRINCIPAL ^^^^^^^^^^^
                    }
                }
            }

            // =================================================================
            // ===                FIM DA CORREÇÃO (MENSALIDADES)             ===
            // =================================================================


            // 8. Se tudo deu certo, excluir o registro pendente
            $this->model->deleteById($id, $id_escola);

            // 9. Commit e Resposta
            $db->commit();
            $this->sendResponse(201, ['success' => true, 'message' => "Matrícula aceita! Aluno (RA: {$ra_sef_gerado}), contrato e mensalidades foram criados com sucesso."]);

        } catch (Exception $e) {
            if ($db && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao aceitar matrícula: ' . $e->getMessage()]);
        }
    }

    /**
     * Reenvia o link do webhook para o N8N
     */
    public function reenviarFormulario($id)
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        try {
            $matricula = $this->model->getById($id, $id_escola);
            if (!$matricula) {
                throw new Exception('Matrícula não encontrada.');
            }

            if ($matricula['status'] === 'Preenchido') {
                throw new Exception('Não é possível reenviar um formulário que já foi preenchido.');
            }

            // Preparar dados para o Webhook N8N
            $linkFormulario = $this->baseUrlFormulario . '/formulario_responsavel.html?token=' . $matricula['token'];

            $payloadN8N = [
                'nome_responsavel' => $matricula['admin_resp_nome'],
                'celular_responsavel' => $matricula['admin_resp_celular'],
                'link_formulario' => $linkFormulario,
                'prazo' => $matricula['prazo']
            ];

            // Enviar para o Webhook (N8N)
            $this->callWebhook($this->webhookUrl, $payloadN8N);

            $this->sendResponse(200, ['success' => true, 'message' => 'Link de matrícula reenviado com sucesso!']);

        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao reenviar: ' . $e->getMessage()]);
        }
    }


    /**
     * Função privada para gerar o PDF do contrato.
     * Reutiliza a lógica do AlunoController.
     */
    // CORREÇÃO: Adicionado $id_escola e $userRole como parâmetros
    private function gerarContratoPDF($id_aluno, $formData, $id_escola, $userRole)
    {
        // $id_escola e $userRole agora vêm dos parâmetros

        $matricula = $formData['matricula_pendente']; // Pega os dados originais

        // CORREÇÃO: Passa $id_escola e $userRole
        $aluno = $this->alunoModel->getById($id_aluno, $id_escola, $userRole);
        if (!$aluno) {
            error_log("gerarContratoPDF: Falha ao buscar dados do aluno recém-criado (ID: $id_aluno)");
            return false;
        }

        $anoLetivo = $formData['ano_inicio'] ?? date('Y');
        $caminhoLogo = __DIR__ . '/../assets/logo_rodape.PNG';
        $logoBase64 = '';
        if (file_exists($caminhoLogo)) {
            $tipoImagem = pathinfo($caminhoLogo, PATHINFO_EXTENSION);
            $dadosImagem = file_get_contents($caminhoLogo);
            $logoBase64 = 'data:image/' . $tipoImagem . ';base64,' . base64_encode($dadosImagem);
        }

        $meses = ["", "Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];
        $data_extenso = "São José dos Campos, " . date('d') . " de " . $meses[(int) date('m')] . " de " . date('Y');
        $html = file_get_contents(__DIR__ . '/../../templates/contrato_template.html');

        $alunoModel = new Aluno();
        $turmaModel = new Turma();

        // CORREÇÃO: Passar os 3 argumentos esperados
        $turma = $turmaModel->getById($matricula['admin_id_turma'], $id_escola, $userRole);

        $valorAnuidadeTotal = (float) ($formData['valor_anuidade_total'] ?? 0);
        $valorMatricula = (float) ($formData['valor_matricula'] ?? 0);
        $valorAnuidadeMaterial = (float) ($formData['valor_anuidade_material'] ?? 0);
        $numParcelasMaterial = (int) ($formData['numero_parcelas_material'] ?? 1);
        $valorParcelaMaterial = ($numParcelasMaterial > 0) ? ($valorAnuidadeMaterial / $numParcelasMaterial) : 0;
        $vencimento = (int) ($formData['dia_vencimento_mensalidades'] ?? 10);
        $valorParcelaCalculado = ($valorAnuidadeTotal > $valorMatricula) ? (($valorAnuidadeTotal - $valorMatricula) / 12) : 0;

        $placeholders = [
            '{{CONTRATANTE_NOME}}' => $formData['nome_resp_financeiro'],
            '{{CONTRATANTE_RG}}' => $formData['rg_responsavel'] ?? '',
            '{{CONTRATANTE_CPF}}' => $formData['cpf_resp_financeiro'],
            '{{CONTRATANTE_ENDERECO}}' => "{$aluno['endereco']}, {$aluno['bairro']} - {$aluno['cidade']}/{$aluno['estado']}, CEP: {$aluno['cep']}",
            '{{ALUNO_NOME}}' => $aluno['nome_aluno'],
            '{{ALUNO_RG}}' => $aluno['rg_aluno'] ?? '',
            '{{ALUNO_CPF}}' => $aluno['cpf_aluno'] ?? '',
            '{{ALUNO_TURMA}}' => $turma ? $turma['nome_turma'] : '___________________',
            '{{ALUNO_ENDERECO}}' => "{$aluno['endereco']}, {$aluno['bairro']} - {$aluno['cidade']}/{$aluno['estado']}, CEP: {$aluno['cep']}",
            '{{ANO_LETIVO}}' => $anoLetivo,
            '{{VALOR_ANUIDADE}}' => number_format($valorAnuidadeTotal, 2, ',', '.'),
            '{{VALOR_MATRICULA}}' => number_format($valorMatricula, 2, ',', '.'),
            '{{VALOR_PARCELA}}' => number_format($valorParcelaCalculado, 2, ',', '.'),
            '{{DIA_VENCIMENTO_PADRAO}}' => $vencimento,
            '{{VALOR_MATERIAL_DIDATICO}}' => number_format($valorAnuidadeMaterial, 2, ',', '.'),
            '{{VALOR_PARCELA_MATERIAL}}' => number_format($valorParcelaMaterial, 2, ',', '.'),
            '{{FIADOR_NOME}}' => '________________________________________________',
            '{{FIADOR_RG}}' => '',
            '{{FIADOR_CPF}}' => '',
            '{{FIADOR_ENDERECO}}' => '________________________________________________',
            '{{CONTRATANTE_ASSINATURA_1}}' => $formData['nome_resp_financeiro'],
            '{{DATA_ASSINATURA}}' => $data_extenso,
            '{{LOGO_SRC}}' => $logoBase64,
            '{{VENCIMENTO_PRIMEIRA_PARCELA}}' => $vencimento,
            '{{VENCIMENTO_ULTIMA_PARCELA}}' => $vencimento
        ];

        foreach ($placeholders as $key => $value) {
            $html = str_replace($key, htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $html);
        }

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $uploadDir = __DIR__ . '/../../uploads/contratos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $safeRa = preg_replace('/[^a-zA-Z0-9-]/', '_', $aluno['ra_sef']);
        $fileName = 'Contrato-' . $safeRa . '-' . uniqid() . '.pdf';
        $filePath = $uploadDir . $fileName;
        file_put_contents($filePath, $dompdf->output());

        // =================================================================
        // ===               INÍCIO DA CORREÇÃO (WEBHOOK)                ===
        // =================================================================
        // Chama a função de webhook recém-adicionada a este controller
        $this->enviarContratoPorWebhook($filePath, $aluno, $formData);
        // =================================================================
        // ===                FIM DA CORREÇÃO (WEBHOOK)                  ===
        // =================================================================

        return 'uploads/contratos/' . $fileName;
    }

    /**
     * Função helper para chamar um webhook
     */
    private function callWebhook($url, $payload)
    {
        $jsonPayload = json_encode($payload);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload)
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Ajuste para ambiente de dev, se necessário

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch) || $httpCode >= 400) {
            $error = curl_error($ch);
            curl_close($ch);
            // Loga o erro, mas não para a execução principal
            error_log("Webhook falhou: HTTP {$httpCode} - " . $response . " | cURL Error: " . $error);
            // Poderia lançar uma exceção se o webhook fosse CRÍTICO
            // throw new Exception("Falha ao contatar o serviço de mensageria.");
        }

        curl_close($ch);
        return $response;
    }

    // =================================================================
    // ===               FUNÇÃO DE WEBHOOK ADICIONADA                ===
    // =================================================================
    /**
     * Envia o contrato para o N8N (para o responsável)
     */
    private function enviarContratoPorWebhook($filePath, $aluno, $formData)
    {
        try {
            if (!file_exists($filePath)) {
                error_log("Webhook: Arquivo PDF não encontrado em {$filePath}");
                return;
            }
            $pdfData = file_get_contents($filePath);
            $pdfBase64 = base64_encode($pdfData);

            $nomeResponsavel = $formData['nome_resp_financeiro'] ?? null;
            $raAluno = $aluno['ra_sef'] ?? null; // Usa ra_sef
            $celularResponsavel = $formData['celular_resp_financeiro'] ?? null;
            $dataNascimentoRaw = $aluno['data_nascimento'] ?? null;
            $dataNascimentoFormatada = null;

            if ($dataNascimentoRaw) {
                try {
                    $dateObj = new DateTime($dataNascimentoRaw);
                    $dataNascimentoFormatada = $dateObj->format('d/m/Y');
                } catch (Exception $e) {
                    error_log("Webhook: Data de nascimento inválida para o RA {$raAluno}: {$dataNascimentoRaw}");
                    $dataNascimentoFormatada = null;
                }
            }

            $payload = [
                'pdf_base64' => $pdfBase64,
                'nome_resp_financeiro' => $nomeResponsavel,
                'celular_resp_financeiro' => $celularResponsavel,
                'ra_aluno' => $raAluno,
                'data_nascimento_aluno' => $dataNascimentoFormatada
            ];

            $jsonPayload = json_encode($payload);
            $webhookUrl = 'https://sistema-crescer-n8n.vuvd0x.easypanel.host/webhook/enviar-contrato';

            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload)
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                error_log("Webhook: Erro ao enviar cURL para n8n: " . curl_error($ch));
            } else {
                error_log("Webhook: Contrato do RA {$raAluno} enviado. Resposta: {$response}");
            }
            curl_close($ch);
        } catch (Exception $e) {
            error_log("Webhook: Exceção ao enviar contrato: " . $e->getMessage());
        }
    }
}