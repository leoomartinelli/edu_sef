<?php
// index.php

// -----------------------------------------------------------------------------
// CONFIGURAÇÕES GERAIS E DE SEGURANÇA
// -----------------------------------------------------------------------------
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// -----------------------------------------------------------------------------
// AUTOLOAD E INCLUSÃO DE DEPENDÊNCIAS
// -----------------------------------------------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/loadEnv.php';
require_once __DIR__ . '/config/Auth.php';
require_once __DIR__ . '/api/controllers/AlunoController.php';
require_once __DIR__ . '/api/controllers/TurmaController.php';
require_once __DIR__ . '/api/controllers/AuthController.php';
require_once __DIR__ . '/api/controllers/MensalidadeController.php';
require_once __DIR__ . '/api/controllers/ProfessorController.php';
require_once __DIR__ . '/api/controllers/DisciplinaController.php';
require_once __DIR__ . '/api/controllers/BoletimController.php';
require_once __DIR__ . '/api/controllers/PendenciaController.php';
require_once __DIR__ . '/api/controllers/ContratoController.php';
require_once __DIR__ . '/api/controllers/UsuarioController.php';
require_once __DIR__ . '/api/controllers/FinanceiroController.php';
require_once __DIR__ . '/api/controllers/AvisoController.php';
require_once __DIR__ . '/api/controllers/ConteudoProgramaticoController.php';
require_once __DIR__ . '/api/controllers/SuperAdminController.php';
require_once __DIR__ . '/api/controllers/MatriculaController.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// -----------------------------------------------------------------------------
// PROCESSAMENTO DA ROTA (ROTEAMENTO)
// -----------------------------------------------------------------------------
$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Define o caminho base do seu projeto.
// Se seu projeto está em http://localhost/, deixe a variável vazia ('').
// Se estiver em http://localhost/Sistema/, coloque '/Sistema'.
$basePath = ''; // <-- A julgar pela sua URL, o correto é VAZIO.

// 1. Remove o caminho base (basePath) do início da URL, se ele existir.
if ($basePath && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}

// 2. Remove o /index.php do início da URL, se ele existir.
if (strpos($path, '/index.php') === 0) {
    $requestUri = substr($path, strlen('/index.php'));
} else {
    $requestUri = $path;
}

// 3. Garante que a URI nunca seja vazia, para evitar erros.
if (strlen($requestUri) > 1 && substr($requestUri, -1) === '/') {
    $requestUri = rtrim($requestUri, '/');
}

// -----------------------------------------------------------------------------
// VALIDAÇÃO DE TOKEN JWT PARA ROTAS PROTEGIDAS
// <-- ESTE BLOCO É FUNDAMENTAL. ELE DECODIFICA O TOKEN E DISPONIBILIZA
// OS DADOS DO USUÁRIO (INCLUINDO id_usuario, role E AGORA id_escola)
// PARA TODO O RESTANTE DA APLICAÇÃO ATRAVÉS DE $GLOBALS['user_data'] -->
// -----------------------------------------------------------------------------
$publicRoutes = [
    '/api/auth/login' => true,
    '/api/auth/forgot-password' => true, // <-- ADICIONE ESTA LINHA
    '/api/auth/reset-password' => true,  // <-- ADICIONE ESTA LINHA
    '/api/auth/confirm-email-and-send-code' => true,
    '/api/matricula/preencher' => true,
];

if (!isset($publicRoutes[$requestUri]) && strpos($requestUri, '/api/') === 0) {
    header('Content-Type: application/json');
    $jwt = getAuthToken(); // Função de config/Auth.php

    if (!$jwt) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token JWT ausente ou inválido.']);
        exit();
    }

    try {
        $decoded = JWT::decode($jwt, new Key(JWT_SECRET_KEY, JWT_ALGORITHM));
        // Disponibiliza os dados do token globalmente
        $GLOBALS['user_data'] = (array) $decoded->data;
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token JWT inválido ou expirado: ' . $e->getMessage()]);
        exit();
    }
} elseif (strpos($requestUri, '/api/') === 0) {
    header('Content-Type: application/json');
}

// -----------------------------------------------------------------------------
// FUNÇÕES AUXILIARES DE AUTORIZAÇÃO
// -----------------------------------------------------------------------------
function requireRole($allowedRoles)
{
    $userRole = $GLOBALS['user_data']['role'] ?? null;
    if (!in_array($userRole, $allowedRoles)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acesso negado. Você não tem permissão para esta ação.']);
        exit();
    }
}

function getUsernameFromToken()
{
    return $GLOBALS['user_data']['username'] ?? null;
}

function getUserIdFromToken()
{
    return $GLOBALS['user_data']['id_usuario'] ?? null;
}

// <-- NOVO: Função auxiliar para obter o ID da escola de forma limpa -->
function getSchoolIdFromToken()
{
    return $GLOBALS['user_data']['id_escola'] ?? null;
}


// -----------------------------------------------------------------------------
// DIRECIONAMENTO DAS ROTAS
// -----------------------------------------------------------------------------
$alunoController = new AlunoController();
$turmaController = new TurmaController();
$authController = new AuthController();
$mensalidadeController = new MensalidadeController();
$professorController = new ProfessorController();
$disciplinaController = new DisciplinaController();
$boletimController = new BoletimController();
$pendenciaController = new PendenciaController();
$contratoController = new ContratoController();
$usuarioController = new UsuarioController();
$financeiroController = new FinanceiroController();
$avisoController = new AvisoController();
$conteudoProgramaticoController = new ConteudoProgramaticoController();
$superAdminController = new SuperAdminController();
$matriculaController = new MatriculaController();

switch (true) {
    case $requestUri === '/' || $requestUri === '/index.php':
        header('Location: public/login.html');
        exit();
    // --- ROTA DE AUTENTICAÇÃO ---
    case $requestUri === '/api/auth/login':
        if ($requestMethod === 'POST')
            $authController->login();
        break;

    // <-- CORRIGIDO: Adicionado o 'case' que estava faltando -->
    case $requestUri === '/api/auth/forgot-password':
        if ($requestMethod === 'POST')
            $authController->requestResetByUsername();
        break;

    case $requestUri === '/api/auth/confirm-email-and-send-code':
        if ($requestMethod === 'POST')
            $authController->confirmEmailAndSendCode();
        break;

    case $requestUri === '/api/auth/reset-password':
        if ($requestMethod === 'POST')
            $authController->resetPassword();
        break;

    // <-- CORRIGIDO: Removido o bloco duplicado/quebrado de 'first-password-change' -->
    case $requestUri === '/api/auth/first-password-change':
        if ($requestMethod === 'POST')
            $authController->firstPasswordChange();
        break;

    case $requestUri === '/api/auth/change-password':
        requireRole(['admin', 'professor', 'aluno']);
        if ($requestMethod === 'PUT')
            $authController->changePassword();
        break;

    // --- ROTAS DE ALUNOS ---
    case $requestUri === '/api/alunos':
        $userRole = $GLOBALS['user_data']['role'] ?? null;
        $usernameFromToken = $GLOBALS['user_data']['username'] ?? null;

        if ($requestMethod === 'GET') {
            if ($userRole === 'admin' || $userRole === 'professor') {
                $alunoController->getAll();
            } elseif ($userRole === 'aluno') {
                $requestedRa = isset($_GET['ra']) ? $_GET['ra'] : null;

                if ($requestedRa && $requestedRa === $usernameFromToken) {
                    $alunoController->getAll();
                } else {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Acesso negado. Alunos só podem consultar os próprios dados.']);
                    exit();
                }
            } else {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
                exit();
            }
        } elseif ($requestMethod === 'POST') {
            requireRole(['admin', 'professor']);
            $alunoController->create();
        }
        break;

    case preg_match('/^\/api\/alunos\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        $id = $matches[1];
        if ($requestMethod === 'GET')
            $alunoController->getById($id);
        elseif ($requestMethod === 'PUT')
            $alunoController->update($id);
        elseif ($requestMethod === 'DELETE')
            $alunoController->delete($id);
        break;

    // --- ROTAS DE TURMAS ---
    case $requestUri === '/api/turmas':
        requireRole(['admin', 'professor']);
        if ($requestMethod === 'GET')
            $turmaController->getAll();
        elseif ($requestMethod === 'POST')
            $turmaController->create();
        break;
    case preg_match('/^\/api\/turmas\/(\d+)\/alunos$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        $idTurma = $matches[1];
        if ($requestMethod === 'GET')
            $alunoController->getAlunosByTurma($idTurma);
        break;


    case preg_match('/^\/api\/turmas\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        $id = $matches[1];
        if ($requestMethod === 'GET')
            $turmaController->getById($id);
        elseif ($requestMethod === 'PUT')
            $turmaController->update($id);
        elseif ($requestMethod === 'DELETE')
            $turmaController->delete($id);
        break;

    case preg_match('/^\/api\/turmas\/(\d+)\/conteudo$/', $requestUri, $matches):
        $idTurma = $matches[1];
        if ($requestMethod === 'GET') {
            requireRole(['admin', 'professor']);
            $conteudoProgramaticoController->getByTurma($idTurma);
        } elseif ($requestMethod === 'POST') {
            requireRole(['admin']);
            $conteudoProgramaticoController->create($idTurma);
        }
        break;

    case preg_match('/^\/api\/conteudo\/(\d+)\/progresso$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        $idConteudo = $matches[1];
        if ($requestMethod === 'POST') {
            $conteudoProgramaticoController->addProgresso($idConteudo);
        }
        break;

    case preg_match('/^\/api\/conteudo\/(\d+)\/grafico$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        $idConteudo = $matches[1];
        if ($requestMethod === 'GET') {
            $conteudoProgramaticoController->getGraficoData($idConteudo);
        }
        break;

    // --- ROTAS DE PROFESSORES ---
    case $requestUri === '/api/professores':
        requireRole(['admin']);
        if ($requestMethod === 'GET')
            $professorController->getAll();
        elseif ($requestMethod === 'POST')
            $professorController->create();
        break;
    case preg_match('/^\/api\/professores\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        $id = $matches[1];
        if ($requestMethod === 'GET')
            $professorController->getById($id);
        elseif ($requestMethod === 'PUT')
            $professorController->update($id);
        elseif ($requestMethod === 'DELETE')
            $professorController->delete($id);
        break;

    case preg_match('/^\/api\/professores\/(\d+)\/turmas$/', $requestUri, $matches):
        requireRole(['admin']);
        $idProfessor = $matches[1];
        if ($requestMethod === 'GET')
            $professorController->getProfessorTurmas($idProfessor);
        elseif ($requestMethod === 'POST')
            $professorController->addTurmaToProfessor($idProfessor);
        break;
    case preg_match('/^\/api\/professores\/(\d+)\/turmas\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        $idProfessor = $matches[1];
        $idTurma = $matches[2];
        if ($requestMethod === 'DELETE')
            $professorController->removeTurmaFromProfessor($idProfessor, $idTurma);
        break;

    case $requestUri === '/api/professor/dashboard':
        requireRole(['professor', 'admin']);
        if ($requestMethod === 'GET') {
            $userId = getUserIdFromToken();
            if ($userId) {
                $professorController->getDashboardData($userId);
            } else {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Não foi possível identificar o usuário a partir do token.']);
            }
        }
        break;

    // --- ROTAS DE DISCIPLINAS ---
    case $requestUri === '/api/disciplinas':
        requireRole(['admin']);
        if ($requestMethod === 'GET')
            $disciplinaController->getAll();
        elseif ($requestMethod === 'POST')
            $disciplinaController->create();
        break;
    case preg_match('/^\/api\/disciplinas\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        $id = $matches[1];
        if ($requestMethod === 'GET')
            $disciplinaController->getById($id);
        elseif ($requestMethod === 'PUT')
            $disciplinaController->update($id);
        elseif ($requestMethod === 'DELETE')
            $disciplinaController->delete($id);
        break;

    case preg_match('/^\/api\/disciplinas\/(\d+)\/turmas$/', $requestUri, $matches):
        requireRole(['admin']);
        $idDisciplina = $matches[1];
        if ($requestMethod === 'GET')
            $disciplinaController->getDisciplinasAssociatedTurmas($idDisciplina);
        break;

    case preg_match('/^\/api\/turmas\/(\d+)\/disciplinas$/', $requestUri, $matches):
        requireRole(['admin']);
        $idTurma = $matches[1];
        if ($requestMethod === 'GET')
            $disciplinaController->getTurmaDisciplinas($idTurma);
        elseif ($requestMethod === 'POST')
            $disciplinaController->addDisciplinaToTurma($idTurma);
        break;
    case preg_match('/^\/api\/turmas\/(\d+)\/disciplinas\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        $idTurma = $matches[1];
        $idDisciplina = $matches[2];
        if ($requestMethod === 'DELETE')
            $disciplinaController->removeDisciplinaFromTurma($idTurma, $idDisciplina);
        break;

    // --- ROTAS DE BOLETIM ---
    case preg_match('/^\/api\/boletim\/turma\/(\d+)\/data$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        $idTurma = $matches[1];
        if ($requestMethod === 'GET')
            $boletimController->getBoletimManagementData($idTurma);
        break;

    case $requestUri === '/api/boletim/aluno':
        $userRole = $GLOBALS['user_data']['role'] ?? null;
        $usernameFromToken = $GLOBALS['user_data']['username'] ?? null;

        if ($requestMethod === 'GET') {
            if ($userRole === 'admin' || $userRole === 'professor') {
                $boletimController->getBoletimAluno();
            } elseif ($userRole === 'aluno') {
                $requestedIdAluno = isset($_GET['id_aluno']) ? (int) $_GET['id_aluno'] : null;

                require_once __DIR__ . '/api/models/Aluno.php';
                $alunoModel = new Aluno();
                $alunoLogado = $alunoModel->getAll(null, $usernameFromToken, null);

                if (!empty($alunoLogado) && $alunoLogado[0]['id_aluno'] === $requestedIdAluno) {
                    $boletimController->getBoletimAluno();
                } else {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Acesso negado. Alunos só podem consultar o próprio boletim.']);
                    exit();
                }
            } else {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acesso negado. Papel de usuário inválido para esta ação.']);
                exit();
            }
        }
        break;

    case $requestUri === '/api/boletim/entry':
        requireRole(['admin', 'professor']);
        if ($requestMethod === 'POST')
            $boletimController->saveBoletimEntry();
        break;

    case preg_match('/^\/api\/boletim\/entry\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        $idBoletim = $matches[1];
        if ($requestMethod === 'DELETE')
            $boletimController->deleteBoletimEntry($idBoletim);
        break;

    case $requestUri === '/api/mensalidades/summary':
        requireRole(['admin', 'professor']);
        if ($requestMethod === 'GET')
            $mensalidadeController->getSummaryByTurma();
        break;
    // --- ROTAS DE MENSALIDADES ---
    case $requestUri === '/api/mensalidades':
        requireRole(['admin', 'professor']);
        if ($requestMethod === 'GET')
            $mensalidadeController->getAll();
        elseif ($requestMethod === 'POST')
            $mensalidadeController->create();
        break;

    case preg_match('/^\/api\/mensalidades\/(\d+)$/', $requestUri, $matches):
        if ($requestMethod === 'GET') {
            $mensalidadeController->getMensalidadeDetails($matches[1]);
        } elseif ($requestMethod === 'DELETE') {
            requireRole(['admin', 'professor']);
            $mensalidadeController->delete($matches[1]);
        }
        break;

    case preg_match('/^\/api\/mensalidades\/(\d+)\/pagar$/', $requestUri, $matches):
        requireRole(['admin', 'professor']);
        if ($requestMethod === 'PUT')
            $mensalidadeController->registerPayment($matches[1]);
        break;

    case $requestUri === '/api/aluno/mensalidades':
        requireRole(['aluno']);
        if ($requestMethod === 'GET') {
            $alunoRa = getUsernameFromToken();
            if ($alunoRa) {
                $mensalidadeController->getByAluno($alunoRa);
            } else {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Não foi possível identificar o aluno a partir do token.']);
            }
        }
        break;

    case preg_match('/^\/api\/aluno\/mensalidades\/(\d+)\/status$/', $requestUri, $matches):
        requireRole(['aluno']);
        if ($requestMethod === 'PUT') {
            $mensalidadeController->updateStudentMensalidadeStatus($matches[1]);
        }
        break;

    case $requestUri === '/api/pendencias/consultar':
        if ($requestMethod === 'POST') {
            $pendenciaController->consultarPendenciasPorCpf();
        }
        break;

    // A rota '/api/pendencias/salvar' continua a mesma de antes.
    case $requestUri === '/api/pendencias/salvar':
        if ($requestMethod === 'POST')
            $pendenciaController->salvarPendencias();
        break;

    // --- ROTAS DE CONTRATOS ---
    case $requestUri === '/api/contratos':
        requireRole(['admin']);
        if ($requestMethod === 'GET')
            $contratoController->getAllContratos();
        break;

    case preg_match('/^\/api\/contratos\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        $idContrato = $matches[1];
        if ($requestMethod === 'DELETE') {
            $contratoController->delete($idContrato);
        }
        break;

    case preg_match('/^\/api\/contratos\/(\d+)\/validar$/', $requestUri, $matches):
        requireRole(['admin']);
        $idContrato = $matches[1];
        if ($requestMethod === 'PUT')
            $contratoController->validarContrato($idContrato);
        break;

    case preg_match('/^\/api\/contratos\/(\d+)\/download$/', $requestUri, $matches):
        requireRole(['admin', 'aluno']);
        $idContrato = $matches[1];
        if ($requestMethod === 'GET') {
            $contratoController->downloadContrato($idContrato);
        }
        break;

    case preg_match('/^\/api\/contratos\/(\d+)\/assinatura$/', $requestUri, $matches):
        requireRole(['admin', 'superadmin']); // Só admins podem ver
        $idContrato = $matches[1];
        if ($requestMethod === 'GET')
            $contratoController->getAssinaturaEletronica($idContrato);
        break;

    // <-- INÍCIO DA NOVA ROTA -->
    case preg_match('/^\/api\/contratos\/(\d+)\/certificado-assinatura$/', $requestUri, $matches):
        requireRole(['admin', 'superadmin']);
        if ($requestMethod === 'GET')
            $contratoController->downloadCertificadoAssinatura($matches[1]);
        break;
    // <-- FIM DA NOVA ROTA -->

    case preg_match('/^\/api\/contratos\/(\d+)\/assinar$/', $requestUri, $matches):
        $idContrato = $matches[1];
        if ($requestMethod === 'POST') {
            $contratoController->assinar($idContrato);
        }
        break;

    case $requestUri === '/api/contratos/uploadAssinado':
        if ($requestMethod === 'POST') {
            $contratoController->uploadAssinado();
        }
        break;
    case $requestUri === '/api/contratos/uploadAssinaturaEletronica':
        if ($requestMethod == 'POST') {
            $contratoController->uploadAssinaturaEletronica();
        }
        break;

    // --- ROTAS DE GERENCIAMENTO DE USUÁRIOS (ADMIN ONLY) ---
    case $requestUri === '/api/usuarios':
        requireRole(['admin']);
        if ($requestMethod === 'GET')
            $usuarioController->getAll();
        elseif ($requestMethod === 'POST')
            $usuarioController->create();
        break;

    case preg_match('/^\/api\/usuarios\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        $id = $matches[1];
        if ($requestMethod === 'GET')
            $usuarioController->getById($id);
        elseif ($requestMethod === 'PUT')
            $usuarioController->update($id);
        elseif ($requestMethod === 'DELETE')
            $usuarioController->delete($id);
        break;

    case preg_match('/^\/api\/usuarios\/(\d+)\/reset-password$/', $requestUri, $matches):
        requireRole(['admin']);
        $id = $matches[1];
        if ($requestMethod === 'PUT')
            $usuarioController->resetPassword($id);
        break;

    // --- ROTAS FINANCEIRAS (ADMIN ONLY) ---
    case $requestUri === '/api/financeiro/tipos-custo':
        requireRole(['admin']);
        if ($requestMethod === 'GET')
            $financeiroController->getTiposCusto();
        elseif ($requestMethod === 'POST')
            $financeiroController->createTipoCusto();
        break;



    case $requestUri === '/api/financeiro/dashboard-summary':
        requireRole(['admin']);
        if ($requestMethod === 'GET')
            $financeiroController->getDashboardSummary();
        break;

    case $requestUri === '/api/financeiro/tipos-receita':
        requireRole(['admin']);
        if ($requestMethod === 'GET')
            $financeiroController->getTiposReceita();
        elseif ($requestMethod === 'POST')
            $financeiroController->createTipoReceita();
        break;

    case $requestUri === '/api/financeiro/transacoes':
        requireRole(['admin']);
        if ($requestMethod === 'GET') {
            $financeiroController->getTransacoes();
        } elseif ($requestMethod === 'POST') {
            $financeiroController->createTransacao();
        } elseif ($requestMethod === 'DELETE') { // <-- NOVO
            $financeiroController->deleteTransacoes();
        }
        break;




    case $requestUri === '/api/financeiro/receitas-mensalidades':
        requireRole(['admin']);
        if ($requestMethod === 'GET')
            $financeiroController->getApprovedMensalidades();
        break;

    case $requestUri === '/api/alunos/importar':
        requireRole(['admin']); // Apenas admins podem importar
        if ($requestMethod === 'POST') {
            $alunoController->importarAlunos();
        }
        break;

    // --- ROTAS DE AVISOS (MURAL) ---
    case $requestUri === '/api/avisos':
        if ($requestMethod === 'GET') {
            $avisoController->getAll();
        } elseif ($requestMethod === 'POST') {
            $avisoController->create();
        }
        break;

    case preg_match('/^\/api\/avisos\/(\d+)$/', $requestUri, $matches):
        $id = $matches[1];
        if ($requestMethod === 'DELETE') {
            $avisoController->delete($id);
        }
        break;

    case $requestUri === '/api/superadmin/escolas':
        requireRole(['super_admin']);
        if ($requestMethod === 'GET')
            $superAdminController->getAllEscolas();
        elseif ($requestMethod === 'POST')
            $superAdminController->createEscola();
        break;

    case preg_match('/^\/api\/superadmin\/escolas\/(\d+)$/', $requestUri, $matches):
        requireRole(['super_admin']);
        $id = $matches[1];
        if ($requestMethod === 'GET')
            $superAdminController->getEscolaById($id);
        elseif ($requestMethod === 'PUT')
            $superAdminController->updateEscola($id);
        elseif ($requestMethod === 'DELETE')
            $superAdminController->deleteEscola($id);
        break;

    case $requestUri === '/api/superadmin/consulta-sef':
        requireRole(['super_admin']);
        if ($requestMethod === 'POST')
            $superAdminController->consultarPendenciasGlobal();
        break;

    case $requestUri === '/api/superadmin/consulta-aluno':
        requireRole(['super_admin']);
        if ($requestMethod === 'POST')
            $superAdminController->consultarAlunoGlobal();
        break;



    case $requestUri === '/api/matricula/iniciar':
        requireRole(['admin']); // Só admin pode iniciar
        if ($requestMethod === 'POST')
            $matriculaController->iniciarProcesso();
        break;

    // (Passo 3) Responsável preenche o form (Rota Pública)
    case $requestUri === '/api/matricula/preencher':
        if ($requestMethod === 'POST')
            $matriculaController->preencherFormulario();
        break;

    // (Passo 4) Admin lista os pendentes
    case $requestUri === '/api/matricula/pendentes':
        requireRole(['admin']);
        if ($requestMethod === 'GET')
            $matriculaController->getMatriculasPendentes();
        break;

    // (Passo 5) Admin vê detalhes ou exclui
    case preg_match('/^\/api\/matricula\/pendentes\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        $id = $matches[1];
        if ($requestMethod === 'GET')
            $matriculaController->getDetalheMatricula($id);
        elseif ($requestMethod === 'DELETE')
            $matriculaController->excluirProcesso($id);
        break;

    // (Passo 5) Admin clica em "Aceitar"
    case preg_match('/^\/api\/matricula\/aceitar\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        if ($requestMethod === 'POST')
            $matriculaController->aceitarMatricula($matches[1]);
        break;

    case preg_match('/^\/api\/matricula\/reenviar\/(\d+)$/', $requestUri, $matches):
        requireRole(['admin']);
        if ($requestMethod === 'POST')
            $matriculaController->reenviarFormulario($matches[1]);
        break;

    // --- ROTA PADRÃO (Endpoint não encontrado) ---
    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint não encontrado.']);
        break;
}