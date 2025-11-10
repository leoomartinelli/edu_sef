<?php
// public/download.php

// Inclua a configuração e a biblioteca JWT
require_once __DIR__ . '/../config/Auth.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../api/models/Contrato.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');

// Obtenha o ID do contrato da URL
$contratoId = $_GET['id_contrato'] ?? null;

if (!$contratoId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID do contrato não fornecido.']);
    exit();
}

// Verifique a autenticação do usuário
$token = getAuthToken();
if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Acesso negado. Token JWT ausente.']);
    exit();
}

try {
    $decoded = JWT::decode($token, new Key(JWT_SECRET_KEY, JWT_ALGORITHM));
    $userData = (array) $decoded->data;

    // Conecte ao banco de dados e obtenha o caminho do arquivo
    $contratoModel = new Contrato();
    $contrato = $contratoModel->getContratoById($contratoId);

    if (!$contrato) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Contrato não encontrado.']);
        exit();
    }
    
    // Verifique se o usuário tem permissão para acessar este contrato
    // Apenas admins e o próprio aluno do contrato podem ver.
    $isAuthorized = ($userData['role'] === 'admin') || ($contrato['id_aluno'] === $userData['id_aluno']);
    
    if (!$isAuthorized) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
        exit();
    }

    // Determine qual PDF exibir (o original ou o assinado)
    $fileType = $_GET['file_type'] ?? 'original';
    $filePath = ($fileType === 'assinado' && $contrato['caminho_pdf_assinado']) 
                ? $contrato['caminho_pdf_assinado'] 
                : $contrato['caminho_pdf'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Arquivo PDF não encontrado no servidor.']);
        exit();
    }

    // Sirva o arquivo para o navegador
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit();

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token JWT inválido ou expirado.']);
    exit();
}

?>