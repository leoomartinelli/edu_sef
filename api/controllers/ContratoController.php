<?php
// api/controllers/ContratoController.php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dompdf\Dompdf;
use Dompdf\Options;
use \iio\libmergepdf\Merger;

require_once __DIR__ . '/../models/Contrato.php';
require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../../config/Auth.php';
require_once __DIR__ . '/../models/Aluno.php';


class ContratoController
{
    private $contratoModel;

    public function __construct()
    {
        $this->contratoModel = new Contrato();
    }

    private function sendResponse($statusCode, $data)
    {
        // Modificado para não enviar cabeçalho se já estivermos enviando um PDF
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code($statusCode);
        }
        echo json_encode($data);
    }

    public function getAllContratos()
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        $userRole = $GLOBALS['user_data']['role'] ?? null;
        if ($userRole !== 'superadmin' && !$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $nomeAluno = $_GET['nome'] ?? null;

        $contratos = $this->contratoModel->getAllContratosComAlunos($id_escola, $userRole, $nomeAluno);
        $pendingCount = $this->contratoModel->getPendingCount($id_escola, $userRole);
        $validatedCount = $this->contratoModel->getValidatedCount($id_escola, $userRole);

        $this->sendResponse(200, [
            'success' => true,
            'data' => $contratos,
            'counts' => ['pending' => $pendingCount, 'validated' => $validatedCount]
        ]);
    }

    public function validarContrato($idContrato)
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        $userRole = $GLOBALS['user_data']['role'] ?? null;
        if ($userRole !== 'superadmin' && !$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $contrato = $this->contratoModel->findById($idContrato, $id_escola, $userRole);
        if (!$contrato) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Contrato não encontrado ou não pertence à sua escola.']);
            return;
        }

        $successValidation = $this->contratoModel->marcarComoValidado($idContrato, $id_escola);

        if ($successValidation) {
            $usuarioModel = new Usuario();
            $idAluno = $contrato['id_aluno'];
            $successRoleUpdate = $usuarioModel->liberarAcessoAluno($idAluno);

            if ($successRoleUpdate) {
                $this->sendResponse(200, ['success' => true, 'message' => 'Contrato validado e acesso do aluno liberado com sucesso.']);
            } else {
                $this->sendResponse(500, ['success' => false, 'message' => 'Contrato validado, mas falhou ao liberar o acesso do aluno.']);
            }
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao validar o contrato.']);
        }
    }

    public function uploadAssinado()
    {
        $token = getAuthToken();
        if (!$token) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Token JWT ausente.']);
            return;
        }
        try {
            $decoded = JWT::decode($token, new Key(JWT_SECRET_KEY, JWT_ALGORITHM));
            $tokenData = (array) $decoded->data;

            if (($tokenData['role'] ?? '') !== 'aluno_pendente') {
                $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Token inválido para esta ação.']);
                return;
            }

            if (!isset($_POST['contract_id']) || !isset($_FILES['signed_pdf'])) {
                $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios ausentes.']);
                return;
            }

            $contratoId = $_POST['contract_id'];
            $file = $_FILES['signed_pdf'];

            if ($file['error'] !== UPLOAD_ERR_OK || $file['type'] !== 'application/pdf') {
                $this->sendResponse(400, ['success' => false, 'message' => 'Erro no upload ou formato de arquivo inválido.']);
                return;
            }

            $uploadDir = __DIR__ . '/../../uploads/contratos_assinados/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $fileName = uniqid('signed_') . '_' . basename($file['name']);
            $filePath = $uploadDir . $fileName;
            $relativePath = 'uploads/contratos_assinados/' . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao salvar o arquivo no servidor.']);
                return;
            }

            $success = $this->contratoModel->updateAssinatura($contratoId, $relativePath);

            if (!$success) {
                $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao atualizar o registro do contrato.']);
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Seu contrato foi recebido com sucesso! A secretaria fará a análise e, assim que for validado, seu acesso ao portal será liberado.'
            ]);

        } catch (Exception $e) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Token inválido ou expirado: ' . $e->getMessage()]);
        }
    }

    public function downloadContrato($idContrato)
    {
        $userData = $GLOBALS['user_data'] ?? null;
        if (!$userData) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $contrato = $this->contratoModel->getContratoById($idContrato, $userData['id_escola'] ?? null, $userData['role']);

        if (!$contrato) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Contrato não encontrado ou não pertence à sua escola.']);
            return;
        }

        $isAuthorized = ($userData['role'] === 'admin') ||
            ($userData['role'] === 'superadmin') ||
            (isset($userData['id_aluno']) && $contrato['id_aluno'] === $userData['id_aluno']);

        if (!$isAuthorized) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $fileType = $_GET['file_type'] ?? 'original';
        $filePath = ($fileType === 'assinado' && !empty($contrato['caminho_pdf_assinado']))
            ? __DIR__ . '/../../' . $contrato['caminho_pdf_assinado']
            : __DIR__ . '/../../' . $contrato['caminho_pdf'];

        if (!file_exists($filePath)) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Arquivo PDF não encontrado no servidor.']);
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit();
    }

    public function delete($idContrato)
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        if (!$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        if (!is_numeric($idContrato)) {
            $this->sendResponse(400, ['success' => false, 'message' => 'ID do contrato inválido.']);
            return;
        }

        if ($this->contratoModel->delete((int) $idContrato, $id_escola)) {
            $this->sendResponse(200, ['success' => true, 'message' => 'Contrato e arquivos associados foram excluídos com sucesso.']);
        } else {
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao excluir o contrato.']);
        }
    }
    public function uploadAssinaturaEletronica()
    {
        $token = getAuthToken();
        if (!$token) {
            $this->sendResponse(401, ['success' => false, 'message' => 'Token JWT ausente.']);
            return;
        }

        $tempSignaturePdf = null; // Para cleanup

        try {
            $decoded = JWT::decode($token, new Key(JWT_SECRET_KEY, JWT_ALGORITHM));
            $tokenData = (array) $decoded->data;

            if (($tokenData['role'] ?? '') !== 'aluno_pendente') {
                $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado. Token inválido para esta ação.']);
                return;
            }

            $inputData = json_decode(file_get_contents('php://input'), true);
            $contratoId = $inputData['contract_id'] ?? null;
            $signatureImage = $inputData['signature_image'] ?? null;

            if (!$contratoId || !$signatureImage) {
                $this->sendResponse(400, ['success' => false, 'message' => 'Campos obrigatórios ausentes.']);
                return;
            }

            $alunoRa = $tokenData['username'] ?? null;
            if (!$alunoRa) {
                $this->sendResponse(401, ['success' => false, 'message' => 'Token inválido (não contém RA/username do aluno).']);
                return;
            }

            // --- Verificação de Segurança (Igual) ---
            $contrato = $this->contratoModel->getContratoParaAssinatura($contratoId);
            if (!$contrato) {
                $this->sendResponse(404, ['success' => false, 'message' => 'Contrato não encontrado.']);
                return;
            }

            $id_aluno_contrato = $contrato['id_aluno'];
            $id_escola_contrato = $contrato['id_escola'];
            $originalPdfPath = __DIR__ . '/../../' . $contrato['caminho_pdf'];

            $alunoModel = new Aluno();
            $alunoDataArray = $alunoModel->getAll($id_escola_contrato, 'admin', '', $alunoRa, '');

            if (empty($alunoDataArray) || !isset($alunoDataArray[0]['id_aluno'])) {
                $alunoModelSef = new Aluno();
                $alunoData = $alunoModelSef->findByRaSef($alunoRa);
                if (!$alunoData || $alunoData['id_escola'] != $id_escola_contrato) {
                    $this->sendResponse(404, ['success' => false, 'message' => 'RA do token não corresponde a um aluno nesta escola.']);
                    return;
                }
                $alunoDataArray[0] = $alunoData;
            }

            $alunoData = $alunoDataArray[0]; // Dados do aluno
            $id_aluno_token_verificado = $alunoData['id_aluno'];
            if ($id_aluno_token_verificado != $id_aluno_contrato) {
                $this->sendResponse(403, ['success' => false, 'message' => 'Conflito de dados. O aluno do token não é o proprietário deste contrato.']);
                return;
            }
            // --- Fim da Verificação de Segurança ---

            // --- Início da Geração do PDF Mesclado ---

            // 1. Gerar o HASH SHA256 do arquivo original
            if (!file_exists($originalPdfPath)) {
                $this->sendResponse(404, ['success' => false, 'message' => 'Arquivo do contrato original não encontrado no servidor.']);
                return;
            }
            $hashDocumento = hash_file('sha256', $originalPdfPath);
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            // 2. Gerar a PÁGINA de assinatura em PDF (em memória)
            $dadosAssinatura = [
                'nome_aluno' => $alunoData['nome_aluno'],
                'ra_aluno' => $alunoData['ra_sef'],
                'id_contrato' => $contratoId,
                'assinatura_base64' => $signatureImage,
                'data_assinatura' => new DateTime(),
                'ip_assinatura' => $ipAddress,
                'hash_documento' => $hashDocumento
            ];

            // Gera o PDF da assinatura e o salva temporariamente
            $signaturePdfContent = $this->_gerarPaginaAssinaturaPdf($dadosAssinatura);
            $tempSignaturePdf = tempnam(sys_get_temp_dir(), 'sig_') . '.pdf';
            file_put_contents($tempSignaturePdf, $signaturePdfContent);

            // 3. Definir o caminho final do arquivo assinado
            $uploadDir = __DIR__ . '/../../uploads/contratos_assinados/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $fileName = 'Contrato-Assinado-' . $contratoId . '-' . $alunoRa . '-' . uniqid() . '.pdf';
            $finalPdfPath = $uploadDir . $fileName;
            $finalPdfRelativePath = 'uploads/contratos_assinados/' . $fileName; // Caminho para salvar no DB

            // 4. Mesclar os PDFs
            $merger = new Merger();
            $merger->addFile($originalPdfPath);  // Adiciona o contrato original
            $merger->addFile($tempSignaturePdf); // Adiciona a página de assinatura
            $mergedPdfContent = $merger->merge();

            // 5. Salvar o PDF mesclado
            if (!file_put_contents($finalPdfPath, $mergedPdfContent)) {
                throw new Exception("Falha ao salvar o PDF mesclado em {$finalPdfPath}");
            }

            // 6. Limpar o arquivo temporário
            if (file_exists($tempSignaturePdf)) {
                unlink($tempSignaturePdf);
            }

            // 7. Preparar dados para salvar no Banco de Dados
            $data = [
                'id_contrato' => $contratoId,
                'id_aluno' => $id_aluno_contrato,
                'assinatura_base64' => $signatureImage,
                'hash_documento_sha256' => $hashDocumento,
                'ip_assinatura' => $ipAddress,
                'caminho_pdf_assinado' => $finalPdfRelativePath // <-- NOVO: O caminho do arquivo mesclado
            ];

            // 8. Salvar no banco (a função no Model será atualizada)
            $success = $this->contratoModel->salvarAssinaturaEletronica($data);

            if (!$success) {
                $this->sendResponse(500, ['success' => false, 'message' => 'Falha ao salvar a assinatura no banco de dados.']);
                return;
            }

            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Seu contrato foi assinado com sucesso! A secretaria fará a análise e, assim que for validado, seu acesso ao portal será liberado.'
            ]);

        } catch (Exception $e) {
            // Limpa o temporário em caso de erro
            if ($tempSignaturePdf && file_exists($tempSignaturePdf)) {
                unlink($tempSignaturePdf);
            }
            $this->sendResponse(401, ['success' => false, 'message' => 'Erro na assinatura: ' . $e->getMessage()]);
        }
    }

    private function _gerarPaginaAssinaturaPdf($data)
    {
        $nomeAluno = htmlspecialchars($data['nome_aluno'], ENT_QUOTES, 'UTF-8');
        $raAluno = htmlspecialchars($data['ra_aluno'], ENT_QUOTES, 'UTF-8');
        $idContrato = htmlspecialchars($data['id_contrato'], ENT_QUOTES, 'UTF-8');
        $imagemAssinatura = $data['assinatura_base64']; // Já está em Base64
        $dataAssinatura = $data['data_assinatura']->format('d/m/Y \à\s H:i:s');
        $ipAssinatura = htmlspecialchars($data['ip_assinatura'], ENT_QUOTES, 'UTF-8');
        $hashDocumento = htmlspecialchars($data['hash_documento'], ENT_QUOTES, 'UTF-8');

        $html = "
            <!DOCTYPE html>
            <html lang='pt-BR'>
            <head>
                <meta charset='UTF-8'>
                <title>Página de Assinatura</title>
                <style>
                    @page { margin: 2cm; }
                    body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11pt; color: #333; }
                    .container { border: 1px solid #ccc; border-radius: 8px; padding: 1.5cm; }
                    h1 { text-align: center; color: #000; font-size: 16pt; margin-top: 0; }
                    h2 { font-size: 13pt; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 25px; }
                    p { line-height: 1.5; }
                    .signature-box { 
                        background-color: #f9f9f9; 
                        border: 1px dashed #aaa; 
                        padding: 15px; 
                        margin-top: 10px; 
                        text-align: center; 
                        page-break-inside: avoid; 
                    }
                    .signature-box img { max-width: 400px; height: auto; }
                    .details { font-size: 9pt; color: #555; word-wrap: break-word; }
                    .details strong { color: #000; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <h1>Certificado de Assinatura Eletrônica</h1>
                    <p>Este documento é parte integrante do contrato de ID {$idContrato} e certifica sua assinatura eletrônica.</p>
                    
                    <h2>Informações do Documento</h2>
                    <p>
                        <strong>Documento:</strong> Contrato de Prestação de Serviços Educacionais<br>
                        <strong>Aluno:</strong> {$nomeAluno}<br>
                        <strong>RA:</strong> {$raAluno}
                    </p>

                    <h2>Informações do Signatário</h2>
                    <div class='signature-box'>
                        <p><strong>Assinatura Digitalizada:</strong></p>
                        <img src='{$imagemAssinatura}' alt='Assinatura'>
                    </div>

                    <h2 style='margin-top: 15px;'>Trilha de Auditoria</h2>
                    <p class='details'>
                        <strong>Data/Hora da Assinatura (UTC-3):</strong> {$dataAssinatura}<br>
                        <strong>Endereço IP do Signatário:</strong> {$ipAssinatura}<br>
                        <br>
                        <strong>Hash SHA256 do Documento Original:</strong><br>
                        <span>{$hashDocumento}</span>
                    </p>
                </div>
            </body>
            </html>
        ";

        // Gerar o PDF com Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true); // Essencial para carregar a imagem Base64
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Retorna o conteúdo do PDF como uma string
        return $dompdf->output();
    }

    public function getAssinaturaEletronica($idContrato)
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        $userRole = $GLOBALS['user_data']['role'] ?? null;

        // Validação de segurança
        if ($userRole === 'aluno') {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }
        if ($userRole !== 'superadmin' && !$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        $assinatura = $this->contratoModel->getAssinaturaEletronica($idContrato, $id_escola, $userRole);

        if ($assinatura) {
            $this->sendResponse(200, ['success' => true, 'data' => $assinatura]);
        } else {
            $this->sendResponse(404, ['success' => false, 'message' => 'Assinatura eletrônica não encontrada ou não pertence à sua escola.']);
        }
    }

    // =================================================================
    // === NOVA FUNÇÃO PARA GERAR O PDF DO CERTIFICADO DE ASSINATURA ===
    // =================================================================
    public function downloadCertificadoAssinatura($idContrato)
    {
        $id_escola = $GLOBALS['user_data']['id_escola'] ?? null;
        $userRole = $GLOBALS['user_data']['role'] ?? null;

        // Validação de segurança (Admin/Superadmin)
        if ($userRole === 'aluno') {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }
        if ($userRole !== 'superadmin' && !$id_escola) {
            $this->sendResponse(403, ['success' => false, 'message' => 'Acesso negado.']);
            return;
        }

        // 1. Buscar os dados da assinatura
        $assinatura = $this->contratoModel->getAssinaturaEletronica($idContrato, $id_escola, $userRole);

        // 2. Buscar os dados do contrato/aluno (findById faz o JOIN com alunos)
        $contrato = $this->contratoModel->findById($idContrato, $id_escola, $userRole);

        if (!$assinatura || !$contrato) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Certificado não pode ser gerado. Dados do contrato ou assinatura não encontrados.']);
            return;
        }

        // 3. Preparar dados para o HTML
        $nomeAluno = htmlspecialchars($contrato['nome_aluno'], ENT_QUOTES, 'UTF-8');
        $raAluno = htmlspecialchars($contrato['ra_sef'], ENT_QUOTES, 'UTF-8'); // Usando ra_sef
        $imagemAssinatura = $assinatura['assinatura_base64']; // Já está em Base64
        $dataAssinatura = (new DateTime($assinatura['data_assinatura']))->format('d/m/Y \à\s H:i:s');
        $ipAssinatura = htmlspecialchars($assinatura['ip_assinatura'], ENT_QUOTES, 'UTF-8');
        $hashDocumento = htmlspecialchars($assinatura['hash_documento_sha256'], ENT_QUOTES, 'UTF-8');

        // Carrega o logo (igual ao AlunoController)
        $caminhoLogo = __DIR__ . '/../assets/logo_rodape.PNG';
        $logoBase64 = '';
        if (file_exists($caminhoLogo)) {
            $tipoImagem = pathinfo($caminhoLogo, PATHINFO_EXTENSION);
            $dadosImagem = file_get_contents($caminhoLogo);
            $logoBase64 = 'data:image/' . $tipoImagem . ';base64,' . base64_encode($dadosImagem);
        }

        // 4. Montar o HTML do Certificado
        $html = "
            <!DOCTYPE html>
            <html lang='pt-BR'>
            <head>
                <meta charset='UTF-8'>
                <title>Certificado de Assinatura</title>
                <style>
                    @page { margin: 2cm; }
                    body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11pt; color: #333; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .header img { width: 250px; opacity: 0.8; }
                    .container { border: 1px solid #ccc; border-radius: 8px; padding: 1.5cm; }
                    h1 { text-align: center; color: #000; font-size: 16pt; margin-top: 0; }
                    h2 { font-size: 13pt; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 25px; }
                    p { line-height: 1.5; }
                    .signature-box { 
                        background-color: #f9f9f9; 
                        border: 1px dashed #aaa; 
                        padding: 15px; 
                        margin-top: 10px; 
                        text-align: center; 
                        page-break-inside: avoid; 
                    }
                    .signature-box img { max-width: 400px; height: auto; }
                    .details { font-size: 9pt; color: #555; word-wrap: break-word; }
                    .details strong { color: #000; }
                </style>
            </head>
            <body>
                <div class='header'>
                    <img src='{$logoBase64}' alt='Logo'>
                </div>
                <div class='container'>
                    <h1>Certificado de Assinatura Eletrônica</h1>
                    
                    <h2>Informações do Documento</h2>
                    <p>
                        <strong>Documento:</strong> Contrato de Prestação de Serviços Educacionais<br>
                        <strong>Aluno:</strong> {$nomeAluno}<br>
                        <strong>RA:</strong> {$raAluno}<br>
                        <strong>ID do Contrato:</strong> {$idContrato}
                    </p>

                    <h2>Informações do Signatário</h2>
                    <p>
                        O documento acima foi assinado eletronicamente pelo responsável do aluno, com os seguintes dados de auditoria:
                    </p>
                    <div class='signature-box'>
                        <p><strong>Assinatura Digitalizada:</strong></p>
                        <img src='{$imagemAssinatura}' alt='Assinatura'>
                    </div>

                    <h2 style='margin-top: 15px;'>Trilha de Auditoria</h2>
                    <p class='details'>
                        <strong>Data/Hora da Assinatura (UTC-3):</strong> {$dataAssinatura}<br>
                        <strong>Endereço IP do Signatário:</strong> {$ipAssinatura}<br>
                        <br>
                        <strong>Hash SHA256 do Documento Original:</strong><br>
                        <span>{$hashDocumento}</span>
                    </p>
                    <br>
                    <p class='details'>
                        Este certificado atesta que a assinatura eletrônica foi capturada e vinculada ao documento original (identificado pelo seu hash SHA256) na data e hora especificadas.
                    </p>
                </div>
            </body>
            </html>
        ";

        // 5. Gerar o PDF com Dompdf
        try {
            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', true); // Essencial para carregar as imagens Base64
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // 6. Enviar o PDF para o navegador
            // Limpa qualquer saída anterior (json) para não corromper o PDF
            ob_clean();

            $fileName = "Certificado-Contrato-{$idContrato}-{$raAluno}.pdf";
            // "Attachment" => 0 faz o PDF abrir no navegador (inline)
            $dompdf->stream($fileName, ["Attachment" => 0]);
            exit();

        } catch (Exception $e) {
            // Se falhar, envia uma resposta de erro JSON
            $this->sendResponse(500, ['success' => false, 'message' => 'Erro ao gerar o PDF do certificado: ' . $e->getMessage()]);
        }
    }
}