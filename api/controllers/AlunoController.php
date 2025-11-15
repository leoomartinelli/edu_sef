<?php
// api/controllers/AlunoController.php
require_once __DIR__ . '/../models/Aluno.php';
require_once __DIR__ . '/../models/Contrato.php';
require_once __DIR__ . '/../models/Mensalidade.php';
require_once __DIR__ . '/../models/Responsavel.php';
require_once __DIR__ . '/../models/Turma.php';
require_once __DIR__ . '/../models/Material.php';
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AlunoController
{
    private $model;
    private $contratoModel;
    private $mensalidadeModel;
    private $responsavelModel;
    private $materialModel;

    public function __construct()
    {
        $this->model = new Aluno();
        $this->contratoModel = new Contrato();
        $this->mensalidadeModel = new Mensalidade();
        $this->materialModel = new Material();
        $this->responsavelModel = new Responsavel();
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
        // NENHUMA MUDANÇA NECESSÁRIA AQUI
        // O modelo foi atualizado para que $searchRa agora consulte a coluna ra_sef
        try {
            $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
            $userRole = $GLOBALS['user_data']['role'] ?? null;

            if ($userRole !== 'superadmin' && !$id_escola) {
                $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Usuário não associado a uma escola.']);
                return;
            }

            $searchName = isset($_GET['search']) ? $_GET['search'] : (isset($_GET['nome']) ? $_GET['nome'] : '');
            $searchRa = isset($_GET['ra']) ? $_GET['ra'] : ''; // O parâmetro da URL pode continuar 'ra'
            $searchTurmaName = isset($_GET['turma']) ? $_GET['turma'] : '';

            $alunos = $this->model->getAll($id_escola, $userRole, $searchName, $searchRa, $searchTurmaName);
            $totalAlunos = $this->model->getTotalCount($id_escola, $userRole);

            $this->sendResponse(200, ['success' => true, 'data' => $alunos, 'total' => $totalAlunos]);

        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro no servidor ao buscar alunos: ' . $e->getMessage()]);
        }
    }

    public function getById($id)
    {
        // NENHUMA MUDANÇA NECESSÁRIA AQUI
        // O modelo foi atualizado para retornar 'ra_sef' e 'ra'
        if (!isset($id) || !is_numeric($id)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do aluno inválido.']);
            return;
        }

        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        $userRole = $GLOBALS['user_data']['role'] ?? null;

        if ($userRole !== 'superadmin' && !$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $aluno = $this->model->getById((int) $id, $id_escola, $userRole);
        if ($aluno) {
            $this->sendResponse(200, ['success' => true, 'data' => $aluno]);
        } else {
            $this->sendResponse(404, ['success' => false, 'message' => 'Aluno não encontrado ou não pertence à sua escola.']);
        }
    }

    public function create()
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;

        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Apenas administradores de uma escola podem criar alunos.']);
            return;
        }

        // =================================================================
        // ===       VALIDAÇÃO ATUALIZADA (ANO DE INÍCIO)                ===
        // =================================================================
        if (json_last_error() !== JSON_ERROR_NONE || empty($data['nome_aluno']) || empty($data['id_turma'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Dados essenciais (aluno, turma) estão faltando.']);
            return;
        }

        if (empty($data['ano_inicio'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'O "Ano de Início (Ano Letivo)" é obrigatório para gerar o RA.']);
            return;
        }

        if (empty($data['data_nascimento'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'A "Data de Nascimento" do aluno é obrigatória para criar o login.']);
            return;
        }
        // =================================================================

        if (empty($data['nome_resp_financeiro']) || empty($data['cpf_resp_financeiro'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'É obrigatório preencher os dados completos (Nome e CPF) do Responsável Financeiro.']);
            return;
        }

        // ... (lógica do responsável financeiro não muda) ...
        $dadosResponsavel = [
            'nome' => $data['nome_resp_financeiro'],
            'cpf' => $data['cpf_resp_financeiro'],
            'data_nascimento' => $data['data_nascimento_resp_financeiro'] ?? null,
            'cep' => $data['cep'],
            'email' => $data['email_resp_financeiro'] ?? null,
            'celular' => $data['celular_resp_financeiro'] ?? null,
            'telefone' => $data['telefone_resp_financeiro'] ?? null,
            'id_escola' => $id_escola
        ];
        $id_responsavel = $this->responsavelModel->findOrCreate($dadosResponsavel);
        if (!$id_responsavel) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao processar os dados do responsável financeiro. (Verifique se o CPF já existe em outra escola)']);
            return;
        }
        $data['id_resp_financeiro'] = $id_responsavel;
        // ... (fim da lógica do responsável) ...

        $result = $this->model->create($data, $id_escola);

        if (is_array($result) && isset($result['id_aluno'])) {
            $id_aluno = $result['id_aluno'];
            $finalMessage = "Aluno (RA: {$result['ra_sef']}) e usuário de login criados com sucesso!";
            $data['ra_sef'] = $result['ra_sef']; // Adiciona o RA gerado ao $data para o PDF

            // ... (lógica de contrato) ...
            $caminho_pdf = $this->gerarContratoPDF($id_aluno, $data);
            if ($caminho_pdf) {
                $this->contratoModel->create(['id_aluno' => $id_aluno, 'caminho_pdf' => $caminho_pdf], $id_escola);
                $finalMessage .= " Contrato gerado com sucesso!";
            } else {
                $finalMessage .= " Falha ao gerar o PDF do contrato.";
            }

            $valorAnuidadeTotal = (float) ($data['valor_anuidade_total'] ?? 0);
            $valorMatricula = (float) ($data['valor_matricula'] ?? 0);
            $diaVencimentoMensalidade = $data['dia_vencimento_mensalidades'] ?? 10;
            $valorMaterial = (float) ($data['valor_anuidade_material'] ?? 0);
            $parcelasMaterial = (int) ($data['numero_parcelas_material'] ?? 1);

            if ($valorAnuidadeTotal > $valorMatricula) {
                try {
                    $this->mensalidadeModel->create([
                        'id_aluno' => $id_aluno,
                        'valor_mensalidade' => $valorMatricula,
                        'data_vencimento' => (new DateTime())->modify('+10 days')->format('Y-m-d'),
                        'descricao' => 'Matrícula'
                    ], $id_escola);

                    $valorMensalidadeCalculada = ($valorAnuidadeTotal - $valorMatricula) / 12;

                    // =================================================================
                    // ===               INÍCIO DA CORREÇÃO (ANO/MÊS)                ===
                    // =================================================================

                    // Pega o ano do formulário (ex: 2026)
                    $anoLetivo = (int) ($data['ano_inicio'] ?? date('Y'));
                    $anoCadastro = (int) date('Y'); // Pega o ano ATUAL (ex: 2025)
                    $mesCadastro = (int) date('m'); // Pega o mês ATUAL (ex: 11)

                    $mesInicial = 1; // Padrão: começa em Janeiro
                    $totalParcelas = 12; // Padrão: 12 parcelas

                    // Se o ano letivo for O MESMO ano do cadastro...
                    if ($anoLetivo == $anoCadastro) {
                        // ...começa no mês atual e gera só as parcelas restantes.
                        $mesInicial = $mesCadastro;
                        $totalParcelas = 12 - $mesCadastro + 1;
                    }
                    // Se o ano letivo for futuro (2026 > 2025), o padrão (Janeiro, 12x) é mantido.

                    $mensalidadesCriadas = 0;

                    if ($totalParcelas > 0) {
                        for ($i = 0; $i < $totalParcelas; $i++) {
                            $mesDaParcela = $mesInicial + $i;

                            // Usa $anoLetivo (o ano do formulário) para criar a data
                            $dataVencimento = new DateTime("{$anoLetivo}-{$mesDaParcela}-{$diaVencimentoMensalidade}");

                            $dadosMensalidade = [
                                'id_aluno' => $id_aluno,
                                'valor_mensalidade' => $valorMensalidadeCalculada,
                                'data_vencimento' => $dataVencimento->format('Y-m-d'),
                                'descricao' => "Mensalidade " . ($mesDaParcela) . "/" . $anoLetivo
                            ];
                            if ($this->mensalidadeModel->create($dadosMensalidade, $id_escola)) {
                                $mensalidadesCriadas++;
                            }
                        }
                    }
                    // Reporta o ano letivo correto na mensagem
                    $finalMessage .= " Matrícula e {$mensalidadesCriadas} mensalidades foram geradas para o ano de {$anoLetivo}.";

                    // =================================================================
                    // ===                FIM DA CORREÇÃO (ANO/MÊS)                  ===
                    // =================================================================

                } catch (Exception $e) {
                    $finalMessage .= " Erro fatal ao gerar cobranças: " . $e->getMessage();
                }
            } else {
                $finalMessage .= " AVISO: Anuidade deve ser maior que a Matrícula. Nenhuma cobrança gerada.";
            }

            // =================================================================
            // ===               LÓGICA DO MATERIAL DIDÁTICO (NOVA)          ===
            // =================================================================
            if ($valorMaterial > 0 && $parcelasMaterial > 0) {
                $valorParcelaMaterial = round($valorMaterial / $parcelasMaterial, 2);

                // Ajuste de centavos para a última parcela
                $valorTotalCalculado = $valorParcelaMaterial * ($parcelasMaterial - 1);
                $valorUltimaParcela = $valorMaterial - $valorTotalCalculado;

                $materialCriadoCount = 0;

                for ($i = 0; $i < $parcelasMaterial; $i++) {
                    $mesDaParcela = $mesInicial + $i;
                    if ($mesDaParcela <= 12) {
                        $dataVencimento = new DateTime("{$anoLetivo}-{$mesDaParcela}-{$diaVencimentoMensalidade}");

                        // Define o valor da parcela (com ajuste na última)
                        $valorDaParcelaAtual = ($i == $parcelasMaterial - 1) ? $valorUltimaParcela : $valorParcelaMaterial;

                        // vvvvvvvvvv ESTA É A NOVA LÓGICA vvvvvvvvvv
                        $this->materialModel->create([
                            'id_aluno' => $id_aluno,
                            'valor_parcela' => $valorDaParcelaAtual, // <-- MUDOU O NOME
                            'data_vencimento' => $dataVencimento->format('Y-m-d'),
                            'descricao' => "Material Didático " . ($i + 1) . "/{$parcelasMaterial}"
                        ], $id_escola);
                        // ^^^^^^^^^^ ESTA É A NOVA LÓGICA ^^^^^^^^^^

                        $materialCriadoCount++;
                    }
                }
                $finalMessage .= " {$materialCriadoCount} cobrança(s) de material didático gerada(s).";
            }
            // =================================================================
            // ===               FIM DA LÓGICA DO MATERIAL                 ===
            // =================================================================

            $this->sendResponse(201, ['success' => true, 'message' => $finalMessage]);

        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao criar aluno: ' . $result]);
        }
    }

    public function update($id)
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        // =================================================================
        // ===               MODIFICADO: RA_SEF REMOVIDO DA VALIDAÇÃO    ===
        // =================================================================
        // O ra_sef não pode ser editado.
        if (!isset($id) || !is_numeric($id) || empty($data['nome_aluno'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios (ID, Nome do Aluno) ausentes.']);
            return;
        }
        $data['id_aluno'] = (int) $id;

        if (empty($data['nome_resp_financeiro']) || empty($data['cpf_resp_financeiro'])) {
            $this->sendResponse(400, ['success' => false, 'message' => 'É obrigatório preencher os dados completos (Nome e CPF) do Responsável Financeiro.']);
            return;
        }

        // ... (lógica do responsável financeiro não muda) ...
        $dadosResponsavel = [
            'nome' => $data['nome_resp_financeiro'],
            'cpf' => $data['cpf_resp_financeiro'],
            'data_nascimento' => $data['data_nascimento_resp_financeiro'] ?? null,
            'cep' => $data['cep'],
            'email' => $data['email_resp_financeiro'] ?? null,
            'celular' => $data['celular_resp_financeiro'] ?? null,
            'telefone' => $data['telefone_resp_financeiro'] ?? null,
            'id_escola' => $id_escola
        ];
        $id_responsavel = $this->responsavelModel->findOrCreate($dadosResponsavel);
        if (!$id_responsavel) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao processar os dados do responsável financeiro.']);
            return;
        }
        $data['id_resp_financeiro'] = $id_responsavel;
        // ... (fim da lógica do responsável) ...

        // O model (Aluno.php) agora cuidará de não atualizar o ra_sef
        $result = $this->model->update($data, $id_escola);

        if ($result === true) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Aluno atualizado com sucesso!']);
        } else {
            // A checagem 'ra_sef_exists' não é mais necessária aqui,
            // pois o ra_sef não pode ser alterado.
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao atualizar aluno.']);
        }
    }

    public function delete($id)
    {
        // NENHUMA MUDANÇA NECESSÁRIA AQUI (deleta por id_aluno)
        if (!isset($id) || !is_numeric($id)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do aluno inválido.']);
            return;
        }
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }
        if ($this->model->delete((int) $id, $id_escola)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Aluno excluído com sucesso!']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao excluir aluno. Pode ser que ele não pertença à sua escola.']);
        }
    }

    public function getAlunosByTurma($idTurma)
    {
        // NENHUMA MUDANÇA NECESSÁRIA AQUI
        // O modelo foi atualizado para retornar ra_sef
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }
        if (!isset($idTurma) || !is_numeric($idTurma)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID da turma inválido.']);
            return;
        }
        $alunos = $this->model->getAlunosByTurmaId((int) $idTurma, $id_escola);
        if ($alunos !== null) {
            $this->sendResponse(200, ['success' => true, 'data' => $alunos]);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao buscar alunos para esta turma.']);
        }
    }

    private function gerarContratoPDF($id_aluno, $formData)
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        $userRole = $GLOBALS['user_data']['role'] ?? null;

        $aluno = $this->model->getById($id_aluno, $id_escola, $userRole);
        if (!$aluno)
            return false;

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

        $valorAnuidadeTotal = (float) ($formData['valor_anuidade_total'] ?? 0);
        $valorMatricula = (float) ($formData['valor_matricula'] ?? 0);
        $valorAnuidadeMaterial = (float) ($formData['valor_anuidade_material'] ?? 0);
        $numParcelasMaterial = (int) ($formData['numero_parcelas_material'] ?? 1);
        $valorParcelaMaterial = ($numParcelasMaterial > 0) ? ($valorAnuidadeMaterial / $numParcelasMaterial) : 0;
        $vencimento = (int) ($formData['dia_vencimento_mensalidades'] ?? 10);
        $valorParcelaCalculado = ($valorAnuidadeTotal > $valorMatricula) ? (($valorAnuidadeTotal - $valorMatricula) / 12) : 0;
        $nome_resp_financeiro_form = $formData['nome_resp_financeiro'] ?? $aluno['nome_resp_financeiro'];
        $cpf_resp_financeiro_form = $formData['cpf_resp_financeiro'] ?? $aluno['cpf_resp_financeiro'];

        $placeholders = [
            '{{CONTRATANTE_NOME}}' => $nome_resp_financeiro_form,
            '{{CONTRATANTE_RG}}' => $formData['rg_responsavel'] ?? '',
            '{{CONTRATANTE_CPF}}' => $cpf_resp_financeiro_form,
            '{{CONTRATANTE_ENDERECO}}' => "{$aluno['endereco']}, {$aluno['bairro']} - {$aluno['cidade']}/{$aluno['estado']}, CEP: {$aluno['cep']}",
            '{{ALUNO_NOME}}' => $aluno['nome_aluno'],
            '{{ALUNO_RG}}' => $aluno['rg_aluno'] ?? '',
            '{{ALUNO_CPF}}' => $aluno['cpf_aluno'] ?? '',
            '{{ALUNO_TURMA}}' => $aluno['nome_turma'] ?? '___________________',
            '{{ALUNO_ENDERECO}}' => "{$aluno['endereco']}, {$aluno['bairro']} - {$aluno['cidade']}/{$aluno['estado']}, CEP: {$aluno['cep']}",
            '{{ANO_LETIVO}}' => $anoLetivo,
            '{{VALOR_ANUIDADE}}' => number_format($valorAnuidadeTotal, 2, ',', '.'),
            '{{VALOR_MATRICULA}}' => number_format($valorMatricula, 2, ',', '.'),
            '{{VALOR_PARCELA}}' => number_format($valorParcelaCalculado, 2, ',', '.'),
            '{{DIA_VENCIMENTO_PADRAO}}' => $formData['vencimento'] ?? '10',
            '{{VALOR_MATERIAL_DIDATICO}}' => number_format($valorAnuidadeMaterial, 2, ',', '.'),
            '{{VALOR_PARCELA_MATERIAL}}' => number_format($valorParcelaMaterial, 2, ',', '.'),
            '{{FIADOR_NOME}}' => $formData['nome_fiador'] ?? '________________________________________________',
            '{{FIADOR_RG}}' => $formData['rg_fiador'] ?? '',
            '{{FIADOR_CPF}}' => $formData['cpf_fiador'] ?? '',
            '{{FIADOR_ENDERECO}}' => $formData['endereco_fiador'] ?? '________________________________________________',
            '{{CONTRATANTE_ASSINATURA_1}}' => $nome_resp_financeiro_form,
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

        // MODIFICADO: Usa ra_sef para o nome do arquivo
        $safeRa = preg_replace('/[^a-zA-Z0-9-]/', '_', $aluno['ra_sef']);

        $fileName = 'Contrato-' . $safeRa . '-' . uniqid() . '.pdf';
        $filePath = $uploadDir . $fileName;
        file_put_contents($filePath, $dompdf->output());
        $this->enviarContratoPorWebhook($filePath, $aluno, $formData);


        return 'uploads/contratos/' . $fileName;
    }

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
            $raAluno = $aluno['ra_sef'] ?? null; // MODIFICADO: Usa ra_sef
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
                'ra_aluno' => $raAluno, // O nome da chave pode continuar 'ra_aluno'
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

    private function getEnderecoViaCep($cep)
    {
        // ... (Esta função não precisa de mudanças) ...
        $cepLimpo = preg_replace('/\D/', '', $cep);
        if (strlen($cepLimpo) !== 8) {
            return null;
        }
        $url = "https://viacep.com.br/ws/{$cepLimpo}/json/";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('Erro cURL ViaCEP: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        $data = json_decode($response, true);
        if ($data && !isset($data['erro'])) {
            return [
                'endereco' => $data['logradouro'] ?? null,
                'bairro' => $data['bairro'] ?? null,
                'cidade' => $data['localidade'] ?? null,
                'estado' => $data['uf'] ?? null,
            ];
        }
        return null;
    }

    public function importarAlunos()
    {
        set_time_limit(0);

        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Escola não identificada.']);
            return;
        }

        if (!isset($_FILES['arquivo_alunos']) || $_FILES['arquivo_alunos']['error'] !== UPLOAD_ERR_OK) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Nenhum arquivo enviado ou erro no upload.']);
            return;
        }

        $filePath = $_FILES['arquivo_alunos']['tmp_name'];
        $turmaModel = new Turma();
        $db = (new Database())->getConnection();

        try {
            $db->beginTransaction();
            $spreadsheet = IOFactory::load($filePath);

            // ... (Lógica da aba 'CRIAR TURMA' não muda) ...
            $sheetTurmas = $spreadsheet->getSheetByName('CRIAR TURMA');
            if ($sheetTurmas) {
                foreach ($sheetTurmas->getRowIterator(2) as $row) {
                    $rowData = $sheetTurmas->rangeToArray('A' . $row->getRowIndex() . ':' . 'B' . $row->getRowIndex(), null, true, false)[0];
                    $nome_turma = trim($rowData[0] ?? '');
                    $periodo = trim($rowData[1] ?? 'Manhã');
                    if (!empty($nome_turma)) {
                        $turmaModel->findOrCreateByNome($nome_turma, $id_escola, [
                            'periodo' => $periodo,
                            'ano_letivo' => date('Y'),
                            'descricao' => ''
                        ]);
                    }
                }
            } else {
                throw new Exception("A aba 'CRIAR TURMA' é obrigatória e não foi encontrada na planilha.");
            }

            $sheetAlunos = $spreadsheet->getSheetByName('IMPORTAR DADOS');
            if (!$sheetAlunos) {
                throw new Exception("A aba 'IMPORTAR DADOS' é obrigatória e não foi encontrada na planilha.");
            }

            $alunosCriados = 0;
            $erros = [];

            foreach ($sheetAlunos->getRowIterator(2) as $row) {
                $rowIndex = $row->getRowIndex();
                $rowData = $sheetAlunos->rangeToArray('A' . $rowIndex . ':' . 'U' . $rowIndex, null, true, false)[0];

                $nome_aluno = trim($rowData[0] ?? '');
                if (empty($nome_aluno))
                    continue;

                try {
                    $serie = trim($rowData[1] ?? '');
                    $rg_aluno = trim($rowData[2] ?? '');
                    $ra_sef = trim($rowData[3] ?? ''); // MODIFICADO: de $ra para $ra_sef
                    $aniversario = !empty($rowData[4]) ? date('Y-m-d', strtotime(str_replace('/', '-', $rowData[4]))) : null;
                    $nome_resp_fin = trim($rowData[5] ?? '');
                    $cpf_resp_fin = preg_replace('/\D/', '', $rowData[6] ?? '');
                    $telefone_resp_fin = trim($rowData[7] ?? '');
                    $cep = trim($rowData[8] ?? '');
                    $data_nasc_resp_fin = !empty($rowData[9]) ? date('Y-m-d', strtotime(str_replace('/', '-', $rowData[9]))) : null;
                    $endereco = null;
                    $bairro = null;
                    $cidade = null;
                    $estado = null;

                    if (!empty($cep)) {
                        $dadosEndereco = $this->getEnderecoViaCep($cep);
                        if ($dadosEndereco) {
                            $endereco = $dadosEndereco['endereco'];
                            $bairro = $dadosEndereco['bairro'];
                            $cidade = $dadosEndereco['cidade'];
                            $estado = $dadosEndereco['estado'];
                        }
                    }

                    $email_resp_fin = trim($rowData[10] ?? '');
                    $nome_resp_ped = trim($rowData[11] ?? '');
                    $cpf_resp_ped = preg_replace('/\D/', '', $rowData[12] ?? '');
                    $cel_resp_ped = trim($rowData[13] ?? '');
                    $data_nasc_resp_ped = !empty($rowData[14]) ? date('Y-m-d', strtotime(str_replace('/', '-', $rowData[13]))) : null; // ATENÇÃO: PLANILHA PARECE TER ERRO AQUI, PEGANDO COLUNA 13 (M)
                    $email_resp_ped = trim($rowData[15] ?? '');
                    $valor_matricula = (float) ($rowData[16] ?? 0);
                    $valor_anuidade = (float) ($rowData[17] ?? 0);
                    $vencimento = (int) ($rowData[18] ?? 10);
                    $valor_anuidade_material = (float) ($rowData[19] ?? 0);
                    $numero_parcelas_material = (int) ($rowData[20] ?? 1);

                    if (empty($cpf_resp_fin) || empty($nome_resp_fin))
                        throw new Exception("Responsável financeiro com nome e CPF são obrigatórios.");

                    $id_responsavel = $this->responsavelModel->findOrCreate([
                        'nome' => $nome_resp_fin,
                        'cpf' => $cpf_resp_fin,
                        'celular' => $telefone_resp_fin,
                        'email' => $email_resp_fin,
                        'cep' => $cep,
                        'data_nascimento' => $data_nasc_resp_fin,
                        'id_escola' => $id_escola
                    ]);

                    $nome_turma = $serie ?: 'Turma Geral';
                    $id_turma = $turmaModel->findOrCreateByNome($nome_turma, $id_escola, [
                        'periodo' => 'Manhã',
                        'ano_letivo' => date('Y'),
                        'descricao' => ''
                    ]);

                    $alunoData = [
                        'nome_aluno' => $nome_aluno,
                        'ra_sef' => $ra_sef, // MODIFICADO: de 'ra' para 'ra_sef'
                        'data_nascimento' => $aniversario,
                        'rg_aluno' => $rg_aluno,
                        'cep' => $cep,
                        'endereco' => $endereco,
                        'bairro' => $bairro,
                        'cidade' => $cidade,
                        'estado' => $estado,
                        'id_turma' => $id_turma,
                        'periodo' => 'Manhã',
                        'nome_resp_pedagogico' => $nome_resp_ped,
                        'cpf_resp_pedagogico' => $cpf_resp_ped,
                        'celular_resp_pedagogico' => $cel_resp_ped,
                        'data_nascimento_resp_pedagogico' => $data_nasc_resp_ped,
                        'email_resp_pedagogico' => $email_resp_ped,
                        'id_resp_financeiro' => $id_responsavel,
                        'cpf_aluno' => null,
                        'telefone_resp_pedagogico' => null,
                        'idade' => null,
                        'complemento' => null,
                        'valor_anuidade_material' => $valor_anuidade_material,
                        'numero_parcelas_material' => $numero_parcelas_material,
                        'ra' => null // ADICIONADO: O novo campo 'ra' fica nulo por padrão
                    ];

                    $result = $this->model->create($alunoData, $id_escola);

                    if (is_numeric($result) && $result > 0) {
                        $alunosCriados++;
                        $id_aluno = $result;

                        // Adiciona dados financeiros ao array para gerar PDF e Mensalidades
                        $alunoData['valor_matricula'] = $valor_matricula;
                        $alunoData['valor_anuidade_total'] = $valor_anuidade;
                        $alunoData['dia_vencimento_mensalidades'] = $vencimento;
                        $alunoData['nome_resp_financeiro'] = $nome_resp_fin;
                        $alunoData['cpf_resp_financeiro'] = $cpf_resp_fin;
                        $alunoData['valor_anuidade_material'] = $valor_anuidade_material;
                        $alunoData['numero_parcelas_material'] = $numero_parcelas_material;

                        $caminho_pdf = $this->gerarContratoPDF($id_aluno, $alunoData);
                        if ($caminho_pdf) {
                            $this->contratoModel->create(
                                ['id_aluno' => $id_aluno, 'caminho_pdf' => $caminho_pdf],
                                $id_escola
                            );
                        }

                        // ... (Lógica de gerar mensalidades não muda) ...
                        $valorAnuidadeTotal = (float) ($valor_anuidade ?? 0);
                        $valorMatricula = (float) ($valor_matricula ?? 0);
                        $diaVencimentoMensalidade = (int) ($vencimento ?? 10);
                        if ($valorAnuidadeTotal > $valorMatricula) {
                            try {
                                $this->mensalidadeModel->create([
                                    'id_aluno' => $id_aluno,
                                    'valor_mensalidade' => $valorMatricula,
                                    'data_vencimento' => (new DateTime())->modify('+10 days')->format('Y-m-d'),
                                    'descricao' => 'Matrícula'
                                ], $id_escola);
                                $valorMensalidadeCalculada = ($valorAnuidadeTotal - $valorMatricula) / 12;
                                $anoAtual = (int) date('Y');
                                $mesAtual = (int) date('m');
                                $parcelasRestantes = 12 - $mesAtual + 1;
                                if ($parcelasRestantes > 0) {
                                    for ($i = 0; $i < $parcelasRestantes; $i++) {
                                        $mesDaParcela = $mesAtual + $i;
                                        if ($mesDaParcela > 12)
                                            break;
                                        $dataVencimento = new DateTime("{$anoAtual}-{$mesDaParcela}-{$diaVencimentoMensalidade}");
                                        $this->mensalidadeModel->create([
                                            'id_aluno' => $id_aluno,
                                            'valor_mensalidade' => $valorMensalidadeCalculada,
                                            'data_vencimento' => $dataVencimento->format('Y-m-d'),
                                            'descricao' => "Mensalidade " . ($mesDaParcela) . "/12"
                                        ], $id_escola);
                                    }
                                }
                            } catch (Exception $e) {
                                $erros[] = "Linha {$rowIndex} (Aluno '{$nome_aluno}'): OK, mas falhou ao gerar mensalidades: " . $e->getMessage();
                            }
                        }

                        // =================================================================
                        // ===               LÓGICA DE MATERIAL (IMPORTAÇÃO)             ===
                        // =================================================================
                        // Usamos as variáveis já lidas da planilha
                        $valorMaterial = (float) ($valor_anuidade_material ?? 0);
                        $parcelasMaterial = (int) ($numero_parcelas_material ?? 0);
                        $diaVencimentoMaterial = (int) ($vencimento ?? 10);

                        if ($valorMaterial > 0 && $parcelasMaterial > 0) {
                            $valorParcelaMaterial = round($valorMaterial / $parcelasMaterial, 2);
                            $valorTotalCalculado = $valorParcelaMaterial * ($parcelasMaterial - 1);
                            $valorUltimaParcela = $valorMaterial - $valorTotalCalculado;

                            $anoAtual = (int) date('Y');
                            $mesAtual = (int) date('m');

                            for ($i = 0; $i < $parcelasMaterial; $i++) {
                                $mesDaParcela = $mesAtual + $i;
                                if ($mesDaParcela > 12)
                                    break; // Não cria parcelas para o ano seguinte na importação

                                $dataVencimento = new DateTime("{$anoAtual}-{$mesDaParcela}-{$diaVencimentoMaterial}");
                                $valorDaParcelaAtual = ($i == $parcelasMaterial - 1) ? $valorUltimaParcela : $valorParcelaMaterial;

                                $this->materialModel->create([
                                    'id_aluno' => $id_aluno,
                                    'valor_parcela' => $valorDaParcelaAtual,
                                    'data_vencimento' => $dataVencimento->format('Y-m-d'),
                                    'descricao' => "Material Didático " . ($i + 1) . "/{$parcelasMaterial}"
                                ], $id_escola);
                            }
                        }
                        // MODIFICADO: Checa o novo retorno e usa a variável correta
                    } else if ($result === 'ra_sef_exists') {
                        throw new Exception("Já existe um aluno com o RA SEF '{$ra_sef}'.");
                    } else {
                        throw new Exception("Erro desconhecido ao criar aluno '{$nome_aluno}'.");
                    }

                } catch (Exception $e) {
                    $erros[] = "Linha {$rowIndex} (Aluno '{$nome_aluno}'): " . $e->getMessage();
                }
            }

            if (!empty($erros)) {
                $db->rollBack();
                $this->sendResponse(400, ['success' => false, 'message' => 'A importação falhou. Verifique os erros e corrija a planilha.', 'errors' => $erros]);
            } else {
                $db->commit();
                $this->sendResponse(201, ['success' => true, 'message' => "Importação concluída! {$alunosCriados} alunos foram cadastrados com sucesso."]);
            }

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro crítico ao processar o arquivo: ' . $e->getMessage()]);
        }
    }
}
